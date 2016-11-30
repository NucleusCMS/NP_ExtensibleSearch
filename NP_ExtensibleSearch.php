<?php
class NP_ExtensibleSearch extends NucleusPlugin {

    function getName()             { return 'Extensible Search'; }
    function getAuthor()           { return 'Andy, sato(na), yamamoto'; }
    function getURL()              { return 'https://github.com/NucleusCMS/NP_ExtensibleSearch'; }
    function getVersion()          { return '0.2'; }
    function getMinNucleusVersion() { return '350'; }
    function getDescription()      { return 'Plugin Extensible Search. It can replace searchresults'; }
    function supportsFeature($key) { return (int)in_array($key, array('SqlTablePrefix', 'SqlApi', 'exclude')); }
    
    function getSqlQuery($query, $amountMonths = 0, &$highlight, $mode = '')
    {
        global $blog, $manager;
        
        $searchclass = new SEARCH($query);
        $highlight   = $searchclass->inclusive;
        
        // if querystring is empty, return empty string
        if ($searchclass->inclusive == '') return '';
        
        $where  = $searchclass->boolean_sql_where('ititle,ibody,imore');
        
        // get list of blogs to search
        $blogs       = $searchclass->blogs;       // array containing blogs that always need to be included
        $blogs[]     = $blog->getID();            // also search current blog (duh)
        $blogs       = array_unique($blogs);      // remove duplicates
        $selectblogs = (count($blogs) > 0) ? sprintf(' and i.iblog in (%s)',implode(',', $blogs)) : '';
        
        $select = $searchclass->boolean_sql_select('ititle,ibody,imore');
        
        $query = 'SELECT i.inumber as itemid ';
        $query .= ' FROM '. $this->_getTableString(array('i'=>'item','m'=>'member','c'=>'category'))
                . ' WHERE i.iauthor=m.mnumber'
                . ' and i.icat=c.catid'
                . ' and i.idraft=0'   // exclude drafts
                . $selectblogs
                    // don't show future items
                . ' and i.itime<=' . mysqldate($blog->getCorrectTime())
                . ' and '.$where;
        
        // take into account amount of months to search
        $items = $this->getArray($query);
        
        $exclusionitems = array();
        $param = array('blogs' => &$blogs, 'items' => &$items, 'query' => $query, 'exclusionitems' => &$exclusionitems);
        $manager->notify('PreSearchResults',$param);
        if (count($exclusionitems))
        {
            $exclusionitems = array_unique($exclusionitems);
            $items          = array_diff($items, $exclusionitems);
            $items          = array_map('intval', $items);
        }
        
        if ($mode == '')
        {
            $queryParams = array();
            
            $_ = array();
            $_['itemid']     = 'i.inumber';
            $_['title']      = 'i.ititle';
            $_['body']       = 'i.ibody';
            $_['author']     = 'm.mname';
            $_['authorname'] = 'm.mrealname';
            $_['i.itime']    = 'i.itime';
            $_['more']       = 'i.imore';
            $_['authorid']   = 'm.mnumber';
            $_['authormail'] = 'm.memail';
            $_['authorurl']  = 'm.murl';
            $_['category']   = 'c.cname';
            $_['catid']      = 'i.icat';
            $_['closed']     = 'i.iclosed';
            if($select)
                 $_['score'] = $select;
            
            $queryParams['fields'] = $this->_getFieldsString($_);
            
            $_ = array();
            $_['i'] = 'item';
            $_['m'] = 'member';
            $_['c'] = 'category';
            $queryParams['from'] = $this->_getTableString($_);
            
            $_ = array();
            $_[] = 'i.iauthor=m.mnumber';
            $_[] = 'and i.icat=c.catid';
            $_[] = 'and i.idraft=0';
            $_[] = $selectblogs;
            $_[] = 'and i.itime<=' . mysqldate($blog->getCorrectTime()); // don't show future items
            $_[] = 'and '.$where;
            $_[] = $items  ? 'and i.inumber in (' . implode(',', $items) . ')' : ' and 1=2 ';
            if ( 0 < $amountMonths ) {
                $localtime = getdate($blog->getCorrectTime());
                $timestamp_start = mktime(0,0,0,$localtime['mon'] - $amountMonths,1,$localtime['year']);
                $_[] = 'and i.itime>' . mysqldate($timestamp_start);
            }
            $queryParams['where']   = join(' ', $_);
            $queryParams['orderby'] = $select ? 'score DESC' : 'i.itime DESC ';
            
            $query = vsprintf("SELECT %s FROM %s WHERE %s ORDER BY %s", $queryParams);
        }
        else
        {
            $query = 'SELECT COUNT(*) FROM '.sql_table('item').' as i WHERE ';
            $query .= $items ? sprintf(' and i.inumber in (%s)', implode(',', $items)) : ' and 1=2 ';
            
        }
        return $query;
    }
    
    function _getFieldsString($fields=array()) {
        
        if(empty($fields)) return '*';
        
        $_ = array();
        foreach($fields as $k=>$v) {
            if($k!==$v) $_[] = "{$v} as {$k}";
            else        $_[] = $v;
        }
        return join(',', $_);
    }
    
    function _getTableString($tables=array()) {
        $_ = array();
        foreach($tables as $k=>$v) {
            $_[] = sql_table($v) . ' as ' . $k;
        }
        return join(',', $_);
    }
    
    /**
     * 
     */
    function getArray($query)
    {
        $res = sql_query($query);
        $array = array();
        while ($itemid = sql_fetch_row($res))
        {
            array_push($array, $itemid[0]);
        }
        return $array;
    }
    /**
     * 
     */
    function search($query, $template, $amountMonths, $maxresults, $startpos)
    {
        global $CONF, $manager, $blog;
        
        $highlight = '';
        $sqlquery  = $this->getSqlQuery($query, $amountMonths, $highlight);
        
        if (!$sqlquery)
        {
            // no query -> show everything
            $extraquery = '';
            $amountfound = $blog->readLogAmount($template, $maxresults, $extraquery, $query, 1, 1);
        }
        else
        {
            // add LIMIT to query (to split search results into pages)
            if (intval($maxresults > 0)) $sqlquery .= ' LIMIT ' . intval($startpos).',' . intval($maxresults);
            
            // show results
            $amountfound = $blog->showUsingQuery($template, $sqlquery, $highlight, 1, 1);
            // when no results were found, show a message
            if ($amountfound == 0)
            {
                $template =& $manager->getTemplate($template);
                $vars = array(
                    'query'  => htmlspecialchars($query),
                    'blogid' => $this->getID()
                );
                echo TEMPLATE::fill($template['SEARCH_NOTHINGFOUND'],$vars);
            }
        } 
        return $amountfound;
    }
    /**
     * doSkinVar
     */
    function doSkinVar($skinType, $template='', $maxresults=50)
    {
        global $blog, $query, $amount, $startpos, $manager;
        
        $param = array('blog' => &$blog, 'type' => 'searchresults');
        $manager->notify('PreBlogContent',$param);
        
        $this->amountfound = $this->search($query, $template, $amount, $maxresults, $startpos);
        $param = array('blog' => &$blog, 'type' => 'searchresults');
        $manager->notify('PostBlogContent',$param);
    }
}
