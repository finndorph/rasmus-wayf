<?php

/**

The tables used at wayf.dk are:

CREATE TABLE stats
    (
        period text COLLATE latin1_swedish_ci NOT NULL,
        sp text COLLATE latin1_swedish_ci NOT NULL,
        idp text COLLATE latin1_swedish_ci NOT NULL,
        users INT(10) unsigned NOT NULL,
        logins INT(10) unsigned NOT NULL,
        updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
    ENGINE=MyISAM DEFAULT CHARSET=utf8
    

Stats is the summarized data for each connection between all the idps and all the sps.
A dash in either the sp or idp or both columns signifies a sum of all users or logins
for all the values in the column.

Sample data:

period sp                     idp                       users logins updated               
------ ---------------------- ------------------------- ----- ------ --------------------- 
2011   https://wayfsp.wayf.dk https://orphanage.wayf.dk 13    322    2011-09-15 07:02:32.0 
2011   https://wayfsp.wayf.dk -                         209   722    2011-09-15 07:02:32.0 
2011   -                      https://orphanage.wayf.dk 31    22019  2011-09-15 07:02:32.0 


CREATE TABLE entities
    (
        id INT NOT NULL AUTO_INCREMENT,
        entityid text NOT NULL,
        sporidp text NOT NULL,
        name_da text NOT NULL,
        name_en text NOT NULL,
        integration_costs INT(10) unsigned,
        integration_costs_wayf INT(10) unsigned,
        number_of_users INT(10) unsigned,
        updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        schacHomeOrganization text,
        PRIMARY KEY (id),
        CONSTRAINT entityid UNIQUE (entityid(100), sporidp(5))
    )
    ENGINE=MyISAM DEFAULT CHARSET=utf8

entities is the relevant data for alle the entities in the federation (from the md repository) plus
the estimated cost of integration - for the entity and for the federation and the number of potential
users.

Sample data:

id entityid                  sporidp name_da            name_en            integration_costs integration_costs_wayf number_of_users updated               schacHomeOrganization 
-- ------------------------- ------- ------------------ ------------------ ----------------- ---------------------- --------------- --------------------- --------------------- 
74 https://orphanage.wayf.dk idp     Wayf Orphanage Idp Wayf Orphanage Idp 20000             (null)                 (null)          2011-03-16 22:59:18.0 orphanage.wayf.dk     

*/

error_reporting(E_ALL - E_NOTICE);
session_name('rasmus');
session_start();

$currencies = array(
    'DKK' => 1,
    '$'   => 0.19,
    '€'   => 0.13,
);

$orderby = array(
	'Name' => 'e.name_da',
	'Users' => 's.users desc',
	'Logins' => 's.logins desc',
);

if (!$_SESSION['loginOK']) {
    $_SESSION['loginOK'] = $_POST['token'] == 'deconstruction70!decentralizing';
    if (!$_SESSION['loginOK']) {
        loginform();
    }
}

class dbconfig {
    const dsn       = 'mysql:dbname=butterfly;host=127.0.0.1';
    const user      = '';
    const password  = '';
}

$defaultparams = array(
    'no' => 0,
    'usersorlogins' => 'users',
    'orderby' => 'e.name_da',
    'id' => null,
    'sporidp' => null,
    'currency' => '$',
);

if ($noof = $_REQUEST['noof']) {
    list($_SESSION['no'], $_SESSION['usersorlogins']) = explode(' ', $noof); 
}

if ($currency = $_REQUEST['currency']) {
    $_SESSION['currency'] = $currency;
}

foreach($defaultparams as $param => $value) {
    if (isset($_REQUEST[$param])) $_SESSION[$param] = $_REQUEST[$param];
    if (!isset($_SESSION[$param]) && isset($value)) $_SESSION[$param] = $value;
}

$function = substr($_SERVER['PATH_INFO'], 1) . '__';

if (function_exists($function)) {
    $function();
} else {
    overview__();
}

function update__() {
     try {
        $fieldnames = array(
            'ic' => 'integration_costs',
            'icw' => 'integration_costs_wayf',
            'us' => 'number_of_users',
        );
        $dbh = new PDO(dbconfig::dsn, dbconfig::user, dbconfig::password);
        $dbh->setAttribute (PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach($_POST['D'] as $id => $fields) {
            foreach($fields as $field => $value) {
                $upd = $dbh->prepare("update entities set $fieldnames[$field] = :value where id = :id");
                $upd->bindValue(':id', $id, PDO::PARAM_INT);
                $upd->bindValue(':value', $value,  PDO::PARAM_INT);
                $upd->execute();      
            }
        }
    } catch (Exception $e) {
        echo 'Connection failed: ' . $e->getMessage();
    }
   
}

function getentities($all = true) {
    try {
        $no = $_SESSION['no'];
        $usersorlogins = $_SESSION['usersorlogins'];
        $orderby = $_SESSION['orderby'];
        $query = <<<eof
SELECT 'wayf_sp' type,
    ROUND((SUM(integration_costs)+SUM(integration_costs_wayf))/1, 0) ic,
    SUM(s.users) users,
    SUM(s.logins) logins
FROM entities e,
    stats s
WHERE e.entityid = s.sp and s.idp = '-' and s.$usersorlogins > :no
UNION ALL
SELECT 'wayf_idp',
    ROUND((SUM(integration_costs)+SUM(integration_costs_wayf))/1, 0),
    SUM(users),
    SUM(logins)
FROM entities e,
    stats s
WHERE e.entityid = s.idp 
AND s.sp = '-' and s.$usersorlogins > :no
UNION ALL
SELECT 'nowayf_sp',
    ROUND((SUM(e.integration_costs))/1, 0) ic,
    0,
    0
FROM entities e, stats sp, stats idp
         WHERE sp.$usersorlogins > :no
        AND idp.sp = '-'
        AND idp.idp <> '-'
        AND idp.idp = sp.idp
        AND sp.sp <> '-'
        and sp.sp = e.entityid
UNION ALL
SELECT 'nowayf_idp',
    ROUND((SUM(e.integration_costs))/1, 0) ic,
    0,
    0
FROM entities e, stats sp, stats idp
         WHERE idp.$usersorlogins > :no
        AND idp.sp = '-'
        AND idp.idp <> '-'
        AND idp.idp = sp.idp
        -- AND sp.$usersorlogins > 50
        AND sp.sp <> '-'
        and sp.idp = e.entityid
eof;
        #print_r($query);
        try {
        $dbh = new PDO(dbconfig::dsn, dbconfig::user, dbconfig::password);
        #$dbh->set_attribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $summastm = $dbh->prepare($query);
        $summastm->execute(array('no' => $no));
        $summa =  $summastm->fetchAll(PDO::FETCH_ASSOC|PDO::FETCH_GROUP);
        #print_r($summa);
        $cc = $GLOBALS['currencies'][$_SESSION['currency']];
        $q = "select e.id, e.*, s.$usersorlogins, e.integration_costs + e.integration_costs_wayf ic 
            from entities e, stats s 
            where ((s.sp = e.entityid and e.sporidp = 'sp' and s.idp = '-') 
                or (s.idp = e.entityid and e.sporidp = 'idp' and s.sp = '-')) and s.$usersorlogins > :no order by $orderby";
       #print_r($q);
        $select = $dbh->prepare($q);
        $select->execute(array(
            'no' => $no,
        ));      
        $entities = $select->fetchAll(PDO::FETCH_ASSOC|PDO::FETCH_GROUP|PDO::FETCH_UNIQUE);
        } catch (PDOException $e) {
            print "what!";
            print_r($e); exit;
        }
        #print_r($entities); exit;
        
/*
        $select = $dbh->prepare("select id, entityid, 'idp' sporidp, name_da, name_en, integration_costs, integration_costs_wayf, number_of_users, users, logins from entities e left join stats s on (s.idp = e.entityid and s.sp = '-') where e.sporidp = 'idp' order by users desc");
        $select->execute();      
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        $select = $dbh->prepare("select id, entityid, 'sp' sporidp,  name_da, name_en, integration_costs, integration_costs_wayf, number_of_users, users, logins from entities e left join stats s on (s.sp = e.entityid and s.idp = '-') where e.sporidp = 'sp'  order by users desc");
        $select->execute();      
        $rows2 = $select->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_merge($rows, $rows2);
*/
        return array('entities' => $entities, 'summa' => $summa);
    } catch (PDOException $e) {
        echo 'Connection failed: ' . $e->getMessage();
    }
}

function admin__() {
        $entities = getentities();
        $rows = $entities['entities'];
        foreach($rows as $row) {
            $eid = $row['entityid'];
            $id = $row['id'];
            $sporidp = $row['sporidp'];
            $integrationcosts = "<input name=\"D[$id][ic]\" value=\"{$row['integration_costs']}\">";
            $integrationcosts_wayf = "<input name=\"D[$id][icw]\" value=\"{$row['integration_costs_wayf']}\">";
            $users = "";
            if ($sporidp == 'idp') $users = "<td><input name=\"D[$id][us]\" value=\"{$row['number_of_users']}\"></td>";
            $tr = <<< eof
<tr><td><a href="?id=$id&sporidp=$sporidp" target=_blank>{$row['name_da']}</a></td><td>$integrationcosts</td><td>$integrationcosts_wayf</td>$users<td class=x>{$row['users']}</td><td>{$row['logins']}</td></tr>
eof;
            $tables[$sporidp] .= $tr;
        }
    $vars['content'] = <<<eof
<form method=post>
    <table>
    <tr>
        <td>
            <table class=inner>
                <tr><th colspan=5>Service Providers</th></tr>
                <tr class=small><th>Name</th><th>Integration<br>Costs</th><th>Integration<br>Costs<br>Wayf</th><th>Unique<br>User<br>Logins</th><th>Logins</th></tr>
                {$tables['sp']}
            </table>
        </td>
        <td>
            <table class=inner>
                <tr><th colspan=6>Identity Providers</th></tr>
                <tr class=small><th>Name</th><th>Integration<br>Costs</th><th>Integration<br>Costs<br>Wayf</th><th>Potential<br>Users</th><th>Unique<br>User<br>Logins</th><th>Logins</th></tr>
                {$tables['idp']}
            </table>
        </td>
    </tr>
    </table>
    </form>
eof;
    print render('rasmus', $vars);
}

function overview__() {
        $entities = getentities();
        $summa = $entities['summa'];
        $rows = $entities['entities'];
        foreach($rows as $row) {
            $idpssps[$row['sporidp']][] = $row['id'];
        }
        #print_r($idpssps);


$sumnowayf = $summa['nowayf_sp'][0]['ic']+$summa['nowayf_idp'][0]['ic'];
$sumwayf = $summa['wayf_sp'][0]['ic']+$summa['wayf_idp'][0]['ic'];
$benefit = $sumnowayf - $sumwayf;

$sumnowayf = c($sumnowayf);
$sumwayf = c($sumwayf);
$benefit = c($benefit);

#print svg($idpssps); exit;

$src2 = "/rasmus.php/svgsrc?lhs=" . urlencode(join('|', $idpssps['sp'])) . '&rhs=' . urlencode(join('|', $idpssps['idp']));

$selectnoof = selectnoof();
$selectcurrency = selectcurrency();
$selectorderby = selectorderby();
$vars['content'] = <<<eof
<div class=c>
<table class=benefit>
<tr><td></td><td></td></tr>
<tr><td>$selectorderby&emsp;$selectnoof&emsp;$selectcurrency</td></td><td></tr>

<tr><td>Integration costs without WAYF:</td><td class=r>$sumnowayf</td></tr>
<tr><td>Integration costs with WAYF:</td><td class=r>$sumwayf</td></tr>
<tr><td>Benefit:</td><td class=r>$benefit</td></tr>
</table>
	<table verticalalign=top>
        <tr>
            <td colspan="2">
                <object id="mothsvgobj" data="$src2" type="image/svg+xml" onload="mothsvg.resize('mothsvgobj');" width="920" height="500"></object>
            </td>
        </tr>
    </table></div>

eof;
    print render('rasmus', $vars);
}

function entityview__() {
    try {
#        $entities = getentities();
#        $entities = $entities['entities'];
        $dbh = new PDO(dbconfig::dsn, dbconfig::user, dbconfig::password);
        $no = $_SESSION['no'];
        $usersorlogins = $_SESSION['usersorlogins'];
        $sporidp = $_SESSION['sporidp'];
        $orderby = $_SESSION['orderby'];
        $id = $_SESSION['id'];
        $rev = array('sp' => 'idp', 'idp' => 'sp');
        $othercolumn = $rev[$sporidp];
        $q = "select period, sp, idp, users, logins, case when $othercolumn = '-' then 'Alle' else e.name_da end name,
            e.id, e.entityid, e2.name_da, e.integration_costs ic, e.integration_costs_wayf icw, e2.integration_costs, e2.integration_costs_wayf, e2.number_of_users 
            from stats s left join entities e on ($othercolumn = e.entityid), entities e2 
            where $sporidp = e2.entityid and e2.id = :id and ($usersorlogins > :no or $othercolumn = '-' ) order by $orderby";
        #print_r($q); exit;
        $select = $dbh->prepare($q);
        $select->execute(array('id' => $id, 'no' => $no));      
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        #print_r($rows); exit;
        $max = 0; 

        $minpct = 1; $maxcount = 15; $maxsum = 98;
        $shownsum = $c = $othercount = 0;
        foreach($rows as $row) {
            #print_r($row);
            if ($row['name'] == 'Alle') {
                $meta = $row;
                continue;
            }
            $sumicw += $row['icw'] + $row['ic'];
            $sum += $row[$usersorlogins];
        }
        foreach($rows as $row) {
            if (!$row['name']) continue;
            if ($row['name'] == 'Alle') continue;
            $pct = $row[$usersorlogins]/$sum*100;
            $idpssps[$othercolumn][] = $row['id'];
            if ($pct < $minpct || $shownsum > $maxsum || $c > $maxcount) {
                $other += $pct;
                $othercount++;
            } else {
                $c++;
                $chd[] = round($pct);
                $shownsum += $pct;
                $chl[] = urlencode($row['name'] . ' (' . round($pct) . '%)');
            }
        }
        
        #print_r($meta);
        $chd = 't:' . join(',', (array)$chd);
        $chl = join('|', (array)$chl);
        if ($other = round($other)) {
            $chd .= ',' . $other;
            $chl .= '|Div (' . $other . '%)';
        }
        
        $lhs = $chl; $rhs = $_REQUEST['entityid'];
        if ($sporidp == 'sp') {
            $lhs = $rhs; $rhs = $chl;
        }
        
        $idpssps[$sporidp][] = $id;
        #print_r($idpssps); exit;
        $src = 'http://chart.apis.google.com/chart?chco=3f8c3f&chs=500x300&&cht=p&chd=' . $chd . '&cht=p&chdl=' . $chl;
        $src2 = "/rasmus.php/svgsrc?lhs=" . urlencode(join('|', (array)$idpssps['sp'])) . '&rhs=' . urlencode(join('|', (array)$idpssps['idp']));
        $selectnoof = selectnoof();
        $selectcurrency = selectcurrency();
        $number_of_users = max(1, $meta['number_of_users']);
        $logins = max(1, $meta['logins']);
        $wayfpct = round($meta[$usersorlogins]/$number_of_users*100);
        $ic = c($meta['integration_costs']);
        $icw = c($meta['integration_costs_wayf']);
        $costsperlogin = c($meta['integration_costs']/$logins, 2);
        $benefit = c($sumicw - $meta['integration_costs'] - $meta['integration_costs_wayf']);
        $selectorderby = selectorderby();
        $xtrainfo = <<< eof
<table class="inner xx">
<tr><td class=l>Users at {$meta['name_da']}</td><td>$number_of_users</td></tr>
<tr><td class=l>Wayf users</td><td>{$meta['users']}</td></tr>
<tr><td class=l>Wayf users in pct</td><td>$wayfpct</td></tr>
<tr><td class=l>Wayf logins</td><td>{$meta['logins']}</td></tr>
<tr><td class=l>Integration costs at {$meta['name_da']}</td><td>$ic</td></tr>
<tr><td class=l>Wayf integration costs for {$meta['name_da']}</td><td>$icw</td></tr>
<tr><td class=l>Cost per login</td><td>$costsperlogin</td></tr>
<tr><td class=l>Benefit for {$meta['name_da']}</td><td>$benefit</td></tr>
</table>
eof;
        $vars['content'] = <<<eof
<table verticalalign=top><tr><td>$selectorderby&emsp;$selectnoof&emsp;$selectcurrency</td></td><td></tr>
<tr><td style="text-align: left;">$xtrainfo</td><td><img src="$src"></td></tr><tr><td colspan=2>
                <object id="mothsvgobj" data="$src2" type="image/svg+xml" onload="mothsvg.resize('mothsvgobj');" width="920" height="500"></object>
</td></tr></table>
eof;
    print render('rasmus', $vars);
#    print "<pre>"; print_r($rows); print_r($entities);
    
    } catch (PDOException $e) {
        echo 'Connection failed: ' . $e->getMessage();
    }
}

function selectnoof() {
$noof = array(0, 2, 5, 10 , 25, 50, 100, 500, 1000, 5000, 10000);
    $presentnoof = $_SESSION['no'] . ' ' . $_SESSION['usersorlogins'];
    
    foreach($noof as $no) {
        $sel = $presentnoof == "$no users" ? ' selected' : '';
        $nousers .= "<option$sel>$no users</option>";
        $sel = $presentnoof == "$no logins" ? ' selected' : '';
        $nologins .= "<option$sel>$no logins</option>";
    }
    
    $select = <<<eof
<form action="?" >Min. for showing: <select onchange="submit();" name=noof>$nousers $nologins</select></form>
eof;
    return $select;
}

function selectcurrency() {
    foreach($GLOBALS['currencies'] as $cc => $d)  {
        $sel = $_SESSION['currency'] == $cc ? ' selected' : '';
        $options .= "<option$sel>$cc</option>";
    }
    $select = <<<eof
<form action="?" >Currency: <select onchange="submit();" name=currency>$options</select></form>
eof;
    return $select;
}

function selectorderby() {
    foreach($GLOBALS['orderby'] as $cc => $d)  {
        $sel = $_SESSION['orderby'] == $d ? ' selected' : '';
        $options .= "<option value=\"$d\" $sel>$cc</option>";
    }
    $select = <<<eof
<form action="?" >Orderby: <select onchange="submit();" name=orderby>$options</select></form>
eof;
    return $select;
}

function c($c, $decimals = 0) {
    $x =  $_SESSION['currency'] . ' ' . number_format($c*$GLOBALS['currencies'][$_SESSION['currency']], $decimals, ',', '.');
    return $x;
}


function svgsrc__() {
    $entities = getentities();
    $entities = $entities['entities'];
    #print_r($entities); exit;
    foreach(explode('|', $_GET['lhs']) as $lhs) {
        $display = $entities[$lhs]['name_da'];
        if (!$display) $display = $entities[$lhs]['sp'];
        #if (!$display) $display = 'no md name for sp';
        $idpssps['sp'][] = array('id' => $lhs, 'name' => htmlspecialchars($display), 'ic' => c($entities[$lhs]['ic']));
    }
    
    foreach(explode('|', $_GET['rhs']) as $rhs) {
        $display = $entities[$rhs]['name_da'];
        if (!$display) $display = $entities[$rhs]['idp'];
        #if (!$display) $display = 'no md name for idp';
        $idpssps['idp'][] = array('id' => $rhs, 'name' => htmlspecialchars($display), 'ic' => c($entities[$rhs]['ic']));
    }
    #print_r($idpssps); exit;
    print svg($idpssps);
}

function svg($idpssps) {
    header('content-type: image/svg+xml; charset=UTF-8');
    
    $nosps = sizeof($idpssps['sp']);
    $noidps = sizeof($idpssps['idp']);
    if ($nosps > $noidps) {
        $c = min(5, ($nosps-1)/2);
        $csp = 0;
        $cidp = max(0, $c - ($noidps-1)/2);
    } else {
        $c = min(5, ($noidps-1)/2);
        $csp = max(0, $c - ($nosps-1)/2);
        $cidp = 0;
    }
    
    $w = 300;
    $h = 20;
    
    $xdiff = 20;
    $xdiff2 = 620;
    $ydiff = 2;
    
    $sptextx = $xdiff + 5; # + $w/2;
    $sptextxr = $xdiff + $w - 5;
    $sptextydiff = $h/2;
    $idptextx = $xdiff2 + 5;#  + $w/2;
    $idptextxr = $xdiff2 + $w - 5;
    $idptextydiff = $h/2;
    
    $wayfspx = 420;
    $wayfboxspy = $c * ($h + $ydiff) + $ydiff;
    $wayfspy = $wayfidpy = $wayfboxspy + $h/2;
    $wayfidpx = 520;
    
    $spy = $csp * ($h + $ydiff);
    $idpy  = $cidp * ($h + $ydiff);
    
    $tx = $wayfspx + 50;
    $ty = $wayfboxspy + $sptextydiff;
    
    $sps = <<<eof
    <a xlink:href="/rasmus.php?" target="_top">
    <rect x="$wayfspx" y="$wayfboxspy" width="100" height="$h"/>
    <text class="c" x="$tx" y="$ty">Wayf</text>
    </a>
eof;
    
    $totalwidth = $xdiff2 + $w;
    $nosps = count($idpssps['sp']);
    $noidps = count($idpssps['idp']);
    $max = max($nosps, $noidps);
    $totalheight = $max*($h + $ydiff) + $h;
    
    $c = 0;
    foreach($idpssps['sp'] as $sp) {
        $y = $c * ($h + $ydiff) + $ydiff + $spy;
        $l1x = $xdiff + $w;
        $l1y = $y + $h/2;
        $ty = $y + $sptextydiff;
        $url = htmlspecialchars("/rasmus.php/entityview?id={$sp['id']}&sporidp=sp");
        
        $anchors['sp'][$sp['id']] = array('x' => $l1x, 'y' => $l1y);
        
        $sps .= <<<eof
    <a xlink:href="$url" target="_top">
    <rect x="$xdiff" y="$y" width="$w" height="$h"/>
    <text class="l" x="$sptextx" y="$ty">{$sp['name']}</text>
    <text class="r" x="$sptextxr" y="$ty">{$sp['ic']}</text>
    </a>
eof;
        $c++;
#     <line x1="$l1x" y1="$l1y" x2="$wayfspx" y2="$wayfspy"/>
   }
    
    $c = 0;
    foreach($idpssps['idp'] as $idp) {
        $y = $c * ($h + $ydiff) + $ydiff + $idpy;
        $l1x = $xdiff2;
        $l1y = $y + $h/2;
        $ty = $y + $idptextydiff;
        $url = htmlspecialchars("/rasmus.php/entityview?id={$idp['id']}&sporidp=idp");

        $anchors['idp'][$idp['id']] = array('x' => $l1x, 'y' => $l1y);
        
        $sps .= <<<eof
    <a xlink:href="$url" target="_top">
    <rect x="$xdiff2" y="$y" width="$w" height="$h"/>
    <text class="l" x="$idptextx" y="$ty">{$idp['name']}</text>
    <text class="r" x="$idptextxr" y="$ty">{$idp['ic']}</text>
    </a>
eof;
        $c++;
#     <line x1="$l1x" y1="$l1y" x2="$wayfidpx" y2="$wayfidpy"/>
   }
   
   foreach($anchors['sp'] as $sp) {
        $sps .= <<<eof
<line x1="{$sp['x']}" y1="{$sp['y']}" x2="$wayfspx" y2="$wayfspy"/>
eof;
   }

    foreach($anchors['idp'] as $idp) {
        $sps .= <<<eof
<line x1="{$idp['x']}" y1="{$idp['y']}" x2="$wayfidpx" y2="$wayfidpy"/>
eof;
    }
    
$svgintro = <<<eof
<?xml version="1.0" standalone="no"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN"
"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">

<svg version="1.1" width="$totalwidth" height="$totalheight"
xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
<style>
	rect
	{
		fill: #eee;
		width: 200;
		stroke-width: 1;
		stroke: #060;
		background-color: #CCC;
	}
	line {
		stroke: #060;
	}
	text {
		font-family: verdana;
		font-size: x-small;
		fill: #060;
		dominant-baseline: central;
	}
    
    text.l {
    	text-anchor: start;
    }
    
    text.c {
    	text-anchor: middle;
    }
    
    text.r {
    	text-anchor: end;
    }

</style>
<g id="abc">
$sps
</g>
</svg>
eof;

return $svgintro;
}

/*

    error_reporting(E_ALL);
    $rows = getentities();
    #print_r($rows);
    foreach($rows as $row) {
        $eid = $row['entityid'];
        $id = $row['id'];
        $sporidp = $row['sporidp'];
        $row['name_da'] = substr($row['name_da'], 0,42);
        $idpssps[$sporidp][] = array('id' => $id, 'name' => htmlspecialchars($row['name_da']));
    }
    #print_r($idpssps);


*/

function render($template, $vars = array()) {
    extract($vars); // Extract the vars to local namespace
    ob_start(); // Start output buffering
    include('../templates/' . $template . '.phtml'); // Include the file
    $content = ob_get_contents(); // Get the content of the buffer
    ob_end_clean(); // End buffering and discard
    return $content; // Return the content
}

function samllogin($idpconfig) {

    if ($response = $_GET['SAMLResponse']) {
    	return json_decode(gzinflate(base64_decode($response)), 1);
	} else {
        $request = array(
            '_ID' => 'z' . sha1(uniqid(mt_rand(), true)),
            '_Version' => '2.0',
            '_IssueInstant' => gmdate('Y-m-d\TH:i:s\Z', time()),
            '_Destination' => $idpconfig['Destination'],
            '_ForceAuthn' => !empty($_REQUEST['ForceAuthn']) ? 'true' : 'false',
            '_IsPassive' => !empty($_REQUEST['IsPassive']) ? 'true' : 'false',
            'AssertionConsumerServiceIndex' => 0,
            '_AttributeConsumingServiceIndex' => 5,
            '_ProtocolBinding' => 'JSON-Redirect',
            'saml:Issuer' => array('__v' => $idpconfig['Issuer']),
        );

        if (!empty($idpconfig['IDPList'])) {
            foreach ((array) $idpconfig['IDPList'] as $idp) {
                $idpList[] = array('_ProviderID' => $idp);
                $request['samlp:Scoping']['samlp:IDPList']['samlp:IDPEntry'] = $idpList;
            }
        }

	    #$request['samlp:Scoping']['_ProxyCount'] = 2;
	    #print_r($request); exit;
        $location = $request['_Destination'];
        $location .= "?SAMLRequest=" . urlencode(base64_encode(gzdeflate(json_encode($request))))
                . ($relayState ? '&RelayState=' . urlencode($relayState) : '');
        Header('Location: ' . $location);
        exit;
    }
}

function attributes2array($attributes)
{
    $res = array();
    foreach ((array) $attributes as $attribute) {
        foreach ($attribute['saml:AttributeValue'] as $value) {
            $res[$attribute['_Name']][] = $value['__v'];
        }
    }
    return $res;
}

function loginform() {
?>
<html>
<body>
<form method=post>
pw: <input type=password name=token>
<input type=submit value=Login>
</form>
</body>
</html>
<?php
    exit;
}
