<?php 
error_reporting(E_ALL);
ini_set('display_errors', 1);
include('../connessione.php');

if(isset($_GET['temp'])){
	    $set_temp=$_GET['temp'];
		$time=time();
		echo $query="UPDATE stanza_piko set set_temp = $set_temp, time=$time";
	    $resul = $link->query($query);
}  
	
	

?>
