<?php
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', 1);
include('../connessione.php');
?>
<!DOCTYPE html> 
<html>
<head>
	<title>Page Title</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	
    
	<script src="../jquery/jquery.js"></script>
	<script src="../jquery/jquery-ui.min.js"></script>
    <script src="../jquery/jquery.knob.js"></script>
    <script>
var setPoint=20;
var stato_attuale;	
function meteo_ajax() {
  var xhttp = new XMLHttpRequest();
  xhttp.onreadystatechange = function() {
    if (this.readyState == 4 && this.status == 200) {
      if(this.responseText=='noajax'){
	  }else{
		var str = this.responseText;
		var res = str.split("#");
		temperatura=Math.round(res[0]*10)/10;
		setPoint=Math.round(res[1]*10)/10;
	  	document.getElementById("tempmis").innerHTML = temperatura +"&deg;";
		document.getElementById("times").innerHTML = res[4];		
		/*document.getElementById("setpoint").innerHTML = res[1];
		document.getElementById("settime").innerHTML = res[4];*/
		document.getElementById("caldaia").src = "../img/"+res[2];
		/*document.getElementById("statoAttuale").src = "img/"+res[5];
		document.getElementById("temptxt").innerHTML = "Temperatura: "+res[6];
		document.getElementById("gradiente").innerHTML = "Grad: "+res[7];
		document.getElementById("info").innerHTML = res[8];*/
		var colore = "#FF0000";
				if(res[9]!= stato_attuale){
				if(res[9]==1){
				colore="#00ff00"
				}
				if(res[9]==3){
				colore="#ff0000"
				}
				if(res[9]==2){
				colore="#fcff00"
				}
				
				$('.dial').trigger(
				'configure',
				{
					"fgColor":colore,
					
				}
				);
				stato_attuale=res[9];
				}
		
	  document.getElementById("setPoint").value=setPoint;
	  }
    }
  };
  xhttp.open("GET", "https://cesana.steplab.net/thermo/piko_ajax.php?lf="+Math.random(), true);
  xhttp.send();
  
}

setInterval(meteo_ajax,10000);

function set_ajax(_status,_temp) {
  var xhttp = new XMLHttpRequest();
  xhttp.onreadystatechange = function() {
  }
xhttp.open("GET", "https://cesana.steplab.net/thermo/piko_set.php?s="+_status+"&temp="+_temp+"&time=157418318900", true);
xhttp.send();
meteo_aiax();
  }
</script>
<script>
$(document).ready(function(){
	
	$(".dial").knob({
	'min':10,
    'max':26,
		'release': function(v){
			
			}
	});

$('.pulsante').button();
$('#minus').click(function(){
	});
$('#plus').click(function(){
	var set_temp=$('#setPoint').val();
	console.log("plus:"+set_temp);
	set_temp++;
	console.log("plus:"+set_temp);
	$('#setPoint').val(set_temp);
	set_ajax(1,set_temp);
	
	
	});
$('#minus').click(function(){
	var set_temp=$('#setPoint').val();
	set_temp--;
	$('#setPoint').val(set_temp);
	set_ajax(1,set_temp);
	
	});

$('.preset').click(function(){
	var temp=$(this).attr('set');
	var status=$(this).attr('status');
	var btn_setPoint=document.getElementById("setPoint").value;
	console.log("setpoint"+btn_setPoint);
	$('#setPoint').val(temp);
	
	set_ajax(status,temp);
	});

   meteo_ajax();	

	});
</script>
<link href="../jquery/jquery-ui.css" rel="stylesheet" type="text/css">
<link href="../termostatto.css?<?php echo rand()?>" rel="stylesheet" type="text/css">

</head>

<body>
<div class="colonna" align="center">
<div>
<p class="testo">Temp.</p>
<p class="testo" id="tempmis" style="font-weight:bold">Wait...</p>
<br><span id="times" style="color:#FFF"></span>
</div>
<div class="pulsante plusmin" set="10" status="5" id="minus">-</div>
<img src="" id="caldaia" height="100px">
<div class="pulsante plusmin" set="10" status="6" id="plus">+</div>
<div><input type="text" value="26" class="dial" id="setPoint"></div>
<div style="margin-top:10px">
<?php
// nomi puslanti
$query="SELECT * FROM `piko_btn`";
$result = $link->query($query);

?>
<?php 
		$a=0;
		while($row = $result->fetch_assoc()){ 
		$a++;
		?>
        <div class="pulsante preset" set="<?php echo $row['valore'] ?>" status="1"><?php echo $row['nome'] ?></div>
        
        <?php } ?>


</div>
</div>

</body>
</html>