<?php
/*
* (C) Kirils Solovjovs, 2010, 2013
* 
* logmon.php - The Web Host Monitor
* 
* This scripts shows realtime info on who is accessing what on your Apache server
* and does so without the use of javascript, but rather by using a funky multipart hack.
*
* Needs a Linux server to run, but can (and may) be customised to work with non-Apache servers.
* 
*/

error_reporting(E_STRICT);
// CONFIG STARTS HERE

// set your CustomLog file format to "%h %l %u %t %v:%p \"%r\" %s %b \"%{Referer}i\" \"%{User-Agent}i\""
define('CONF_LOGFILE',''); // set FULL PATH to logfile here
define('CONF_TIMEOUT',5); //seconds
define('CONF_TIMEZONE','Europe/Riga');
define('CONF_WINDOWSIZE',60); //seconds

//NB! update #statusicon background in mon.css if you increase this
define('CONF_REFRESH',1); //minimum sleep between refresh, seconds


// CONFIG ENDS HERE

set_time_limit(0);
ini_set('zlib.output_compression','1');
ini_set('zlib.output_compression_level','9');

$ff=preg_match('/^Mozilla\/[5-9][0-9\.]*\s.*\sFirefox\/([0-9\.]+)$/',$GLOBALS['HTTP_USER_AGENT'],$v);
$bv='';
foreach (explode('.',$v[1]) as $sv)
 $bv.=str_pad($sv,3,'0',STR_PAD_LEFT);

$bv=str_pad(substr($bv,0,9),9,'0');

if(!$ff || ($bv<3005000))
 die('Sorry, only Firefox 3.5+ is currently supported.');

if(CONF_LOGFILE==='')
 die('Please configure settings by opening logmon.php with your favorite text editor.');
elseif(!is_readable(CONF_LOGFILE)){
 $user=posix_getpwuid(posix_geteuid());
 $group=posix_getgrgid(posix_getegid());
 die(htmlspecialchars(CONF_LOGFILE.' cannot be opened. Please make sure that the file exists and is readable by user '
  .$user['name'].' or group '.$group['name'].'!'));
}
$boundary=uniqid();
header('Content-Type: multipart/x-mixed-replace;boundary='.$boundary);
echo("\n--$boundary\n");

date_default_timezone_set(CONF_TIMEZONE);
$handle = popen("tail -f -- ".escapeshellarg(CONF_LOGFILE)." 2>&1", 'r');
stream_set_blocking($handle, 0);
$db=new clients();

while(!feof($handle)) {
 do{
  $buffer = trim(fgets($handle));
  if($buffer=='')
   break;
  $d=dissect($buffer);
  $d['date']=strtotime($d['date']);

  $db->got($d);
 } while ($buffer>'');
 $db->showme('domain');
 sleep(CONF_REFRESH);
}
pclose($handle);
echo("Content-Type: text/plain\nContent-Length: 0\n--$boundary--");



// CLASS STARTS HERE

class clients{
 private $clientlist;
 function  __construct (){
  $this->clientlist=array();
 }
 function got($e){
  $g=0;
  foreach($this->clientlist as $n=>$v){
   if(($v['ip']==$e['ip'])&&($v['domain']==$e['domain'])){
//    $this->clientlist[$n]['r']++;
//    $this->clientlist[$n]['date']=$e['date'];
    $this->clientlist[$n]['requests'][]=$e; //bottom
//    array_unshift($this->clientlist[$n]['requests'],$e); //top
    $g=1;
    break;
   }
  }
  if(!$g) { //new IP/domain pair
//    $e['r']=1;
    $e['requests'][]=$e;
//    array_unshift($this->clientlist,$e); //add to top
    $this->clientlist[]=$e;
 
  }
 }

 function clear($seconds=60){ //BUG: Y2038 bug here
  foreach($this->clientlist as $m=>$v)
   if(time()-$v['date']>=$seconds){
    $mdate=2147483648;
    foreach($v['requests'] as $n=>$t)
     if(time()-$t['date']>=$seconds)
      unset($this->clientlist[$m]['requests'][$n]);
     else if($t['date']<$mdate){
      $mdate=$t['date'];
      $newmaster=$t;
     }
    if($mdate==2147483648)
     unset($this->clientlist[$m]);
    else foreach($newmaster as $k=>$v)
     $this->clientlist[$m][$k]=$v;
    
   }
 }

 function showme($groupby='domain'){
  $this->clear(CONF_WINDOWSIZE);
  $grouping=array();
  foreach($this->clientlist as $n=>$v){
   $grouping[$v[$groupby]][]=&$this->clientlist[$n];
  }
  $status_codes = array( //RFC2616
		 100=>'100 Continue',
		 101=>'101 Switching Protocols',

		 200=>'200 OK',
		 201=>'201 Created',
		 202=>'202 Accepted',
		 203=>'203 Non-Authoritative Information',
		 204=>'204 No Content',
		 205=>'205 Reset Content',
		 206=>'206 Partial Content',

		 300=>'300 Multiple Choices',
		 301=>'301 Moved Permanently',
		 302=>'302 Found',
		 303=>'303 See Other',
		 304=>'304 Not Modified',
		 305=>'305 Use Proxy',
		 306=>'306 Switch Proxy (Obsolete)',
		 307=>'307 Temporary Redirect',

		 400=>'400 Bad Request',
		 401=>'401 Unauthorized',
		 402=>'402 Payment Required',
		 403=>'403 Forbidden',
		 404=>'404 Not Found',
		 405=>'405 Method Not Allowed',
		 406=>'406 Not Acceptable',
		 407=>'407 Proxy Authentication Required',
		 408=>'408 Request Timeout',
		 409=>'409 Conflict',
		 410=>'410 Gone',
		 411=>'411 Length Required',
		 412=>'412 Precondition Failed',
		 413=>'413 Request Entity Too Large',
		 414=>'414 Request-URI Too Long',
		 415=>'415 Unsupported Media Type',
		 416=>'416 Requested Range Not Satisfiable',
		 417=>'417 Expectation Failed',

		 500=>'500 Internal Server Error',
		 501=>'501 Not Implemented',
		 502=>'502 Bad Gateway',
		 503=>'503 Service Unavailable',
		 504=>'504 Gateway Timeout',
		 505=>'505 HTTP Version Not Supported'
 	);

  boundary_start();
  echo("<div id='statusicon'></div>");
  echo("<ul class='domlist'>");

  foreach($grouping as $dom=>$va){
   
   $repstr='';
   $repcnt=sizeof($va);
//   if($repcnt>1)
//    $repstr.="($repcnt) ";
   for(;$repcnt>100;$repcnt-=100)
    $repstr.='⁂';
   $repstr.=' ';
   for(;$repcnt>10;$repcnt-=10)
    $repstr.='⁑';
   $repstr.=' ';
   $repstr.=str_repeat('*',$repcnt);

   $repstr=preg_replace('/((.)\2\2\2\2)/','$1 ',$repstr);
   $repstr=trim(preg_replace('/\s+/',' ',$repstr));

   echo("<li><span class='domain'>$dom</span> <span class='instances'>$repstr</span>");
   echo("<ul class='iplist'>");
   foreach($va as $ip){
    echo("<li><span class='ip'><a href='http://net.02.lv/whois?param=".$ip['ip']."'>"
     .(ip2long($ip['ip'])?gethostbyaddr($ip['ip']):$ip['ip'])."</a></span>"
     .($ip['referer']?" <span class='referer'><a href='".$ip['referer']."'>"
     .$ip['referer']."</a></span>":'').($ip['ua']?" <span class='ua'><a href='"
     ."http://www.useragentstring.com/index.php?getText=all&amp;uas=".urlencode($ip['ua'])
//     ."http://my-addr.com/user_agent_string_analysis-and-user_agent_details/user"
//     ."_agent_lookup-user_agent_checker_tool.php?user_agent=".urlencode($ip['ua'])
     ."'>".$ip['ua']."</a></span>":'')."<ul class='filelist'>");
    $dati=array();


    $lastrd=0;
    foreach($ip['requests'] as $r){
     $subrq=($r['date']-$lastrd<3);
     $dati[]=array('<span title="'.$r['protocol'].'/'.$r['version'].' '
      .$status_codes[$r['status']].'" class="status sg'.$r['status'][0].' s'
      .$r['status'].((strtoupper($r['protocol'])=='HTTP')?(($r['version']=='1.0')
      ?' oldversion':''):' nonstandart').'"><a href="'.((strtoupper($r['protocol'])=='HTTP')
      ?((($r['version']=='1.0')?('http://www.w3.org/Protocols/HTTP/1.0/spec.html#Code'
      .$r['status']):('http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html#sec10.'
      .$r['status'][0].'.'.(substr($r['status'],1)+1)))):'').'">'.$r['status']
      .'</a></span> <span class="method">'.$r['method'].'</span> <span class="file">'
      .(((strtoupper($r['method'])=='GET')&&(strtoupper($r['protocol'])=='HTTP'))?'<a href="'
      .(($r['port']==443)?'https':$r['protocol']).'://'.$r['domain'].':'
      .$r['port'].$r['file'].'">'.$r['file'].'</a>':$r['file'])
      .'</span>'.($r['size']?' <span class="size">'.(($r['size']>=1048576)
      ?(round($r['size']/1048576,2).'Mi'):(($r['size']>=1024)?(round($r['size']/1024,2)
      .'Ki'):$r['size'])).'B</span>':''), '<span class="time">'.date('H:i:s',$r['date'])
      .'</span> ',($subrq?'<li class="subrq">':'<li>'),'</li>');
      $lastrd=$r['date'];
     }

    foreach(uniq_c($dati) as $f)
     echo($f);
    echo("</ul></li>\n");
   }
 
   echo("</ul></li>\n");
  }
  echo("</ul>");
  boundary_end();

 }
}

function uniq_c($ar){
 $car=$compar=$prear=$postar=array();
 foreach ($ar as $v){
  $car[$v[0]]++;
  if(is_null($compar[$v[0]])||($v[1]<$compar[$v[0]])){
// max() will show the last access date
   $postar[$v[0]]=$v[3];
   $prear[$v[0]]=$v[2];
   $compar[$v[0]]=$v[1];
  }
 }
 $mar=array();
 foreach($car as $k=>$v)
  $mar[]=$prear[$k].$compar[$k].$k.(($v>1)?" <span class='repeat'>($v)</span>":'').$postar[$k];
 return $mar;
}

function boundary_start(){
  echo("Content-Type: text/html\n\n");
  echo('<!--<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" '
  .'"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">-->
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="lv" lang="lv">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />');
  echo("<meta http-equiv='refresh' content='".CONF_TIMEOUT."' /><title>The Web Host Monitor - "
  .date("l, j. F Y H:i:s")."</title>");
?>
 <style type="text/css">
  @import "/mon.css";
 </style>
</head><body>
<?
}

function boundary_end(){
  global $boundary;
  echo("</body></html>");
  echo("\n--$boundary\n");
  ob_flush();
  flush();
}

function dissect($buffer){
 preg_match('/^([^\s]+) ([^\s]+) ([^\s]+) \[([^\]]+)\] ([^:]+):([^\s]+) "([^\s]+)'.
  ' ([^\s]+) ([^\s\/]+)\/?([^\s]+)" ([^\s]+) ([^\s]+) "(.*?)" "(.*?)"/',$buffer,$dati);
 $maps=array('ip', 'host', 'user', 'date', 'domain', 'port', 'method', 'file',
  'protocol', 'version', 'status', 'size', 'referer', 'ua');
 $i=0; 
 $d=array();
 foreach($maps as $m){
  $d[$m]=htmlspecialchars($dati[++$i]);
  if($d[$m]=='-')
   $d[$m]=NULL;
 }
 return $d;
}
?>
