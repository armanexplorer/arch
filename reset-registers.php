<?php
$zero_32bit = str_pad('0',32,'0');
$reg = array_fill($start=0,16,$zero_32bit);
$res = file_put_contents("registers.txt",json_encode($reg));
if($res===false)
{
	echo "failed to reset registers";
	return;
}
echo "success";
?>