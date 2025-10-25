<?php 
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', 1);
include('../connessione.php');

$query="SELECT * FROM `stanza_piko`";
$result = $link->query($query);
$row = $result->fetch_assoc();
$temp=$row['temp'];
$set=$row['set_temp'];
$stato=$row['caldaia'];
$rele=$row['time_rele'];
if($stato==1)$statico="caldaiaon.png";
if($stato==0) $statico="temperatura_icon.png";
$time=$row['time'];
$rele1=date("d/m H:i",$rele);
echo $temp.'#'.$set.'#'.$statico.'#'.$time.'#'.$rele1.'#brin';	

?>