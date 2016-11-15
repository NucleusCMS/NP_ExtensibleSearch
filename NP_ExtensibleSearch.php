<?php
class NP_ExtensibleSearch extends NucleusPlugin {

    function getName()             { return 'Extensible Search'; }
    function getAuthor()           { return 'Andy, sato(na)'; }
    function getURL()              { return 'http://www.matsubarafamily.com/lab/'; }
    function getVersion()          { return '0.12'; }
    function getMinNucleusVersion() { return '350'; }
    function getDescription()      { return 'Plugin Extensible Search. It can replace searchresults'; }
    function supportsFeature($key) { return (int)in_array($key, array('SqlTablePrefix', 'exclude')); }
    /**
     * 
     */
    function getSqlQuery($query, $amountMonths = 0, &$highlight, $mode = '')
    {
        global $blog, $manager;
        
        $searchclass = new SEARCH($query);
        $highlight   = $searchclass->inclusive;
        
        // if querystring is empty, return empty string
        if ($searchclass->inclusive == '') return '';
        
        $where  = $searchclass->boolean_sql_where('ititle,ibody,imore');
        $select = $searchclass->boolean_sql_select('ititle,ibody,imore');
        
        // get list of blogs to search
        $blogs       = $searchclass->blogs;       // array containing blogs that always need to be included
        $blogs[]     = $blog->getID();            // also search current blog (duh)
        $blogs       = array_unique($blogs);      // remove duplicates
        $selectblogs = '';
        if (count($blogs) > 0) $selectblogs = ' and i.iblog in (' . implode(',', $blogs) . ')';
        $sqlquery = 'SELECT i.inumber as itemid ';
        if ($select)
        {
            $sqlquery .= ', '.$select. ' as score ';
        }
        $sqlquery .= ' FROM '.sql_table('item').' as i, '.sql_table('member').' as m, '.sql_table('category').' as c'
                . ' WHERE i.iauthor=m.mnumber'
                . ' and i.icat=c.catid'
                . ' and i.idraft=0'   // exclude drafts
                . $selectblogs
                    // don't show future items
                . ' and i.itime<=' . mysqldate($blog->getCorrectTime())
                . ' and '.$where;
        
        // take into account amount of months to search
        if ($amountMonths > 0)
        {
            $localtime = getdate($blog->getCorrectTime());
            $timestamp_start = mktime(0,0,0,$localtime['mon'] - $amountMonths,1,$localtime['year']);
            $sqlquery .= ' and i.itime>' . mysqldate($timestamp_start);
        }
        
        $items          = $this->getArray($sqlquery);
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
            $sqlquery = 'SELECT i.inumber as itemid, i.ititle as title, i.ibody as body, m.mname as author, m.mrealname as authorname, i.itime, i.imore as more, m.mnumber as authorid, m.memail as authormail, m.murl as authorurl, c.cname as category, i.icat as catid, i.iclosed as closed';
            $sqlquery .= ' FROM '.sql_table('item').' as i, '.sql_table('member').' as m, '.sql_table('category').' as c'
                . ' WHERE i.iauthor=m.mnumber'
                . ' and i.icat=c.catid';
            $sqlquery .= $items ? ' and i.inumber in (' . implode(',', $items) . ')' : ' and 1=2 ';
            $sqlquery .= $select ? ' ORDER BY score DESC' : ' ORDER BY i.itime DESC ';
        }
        else
        {
            $sqlquery = 'SELECT COUNT(*) FROM '.sql_table('item').' as i WHERE ';
            $sqlquery .= $items ? ' and i.inumber in (' . implode(',', $items) . ')' : ' and 1=2 ';
            
        }
        return $sqlquery;
    }
    /**
     * 
     */
    function getArray($query)
    {
        $res = sql_query($query);
        $array = array();
        while ($itemid = mysql_fetch_row($res))
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
             $amountfound = $blog->readLogAmount($template, $maxresults, $extraQuery, $query, 1, 1);
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
