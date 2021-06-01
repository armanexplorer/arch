<?php
$memory = array_fill($start=0,$number_of_elements=65536,$val=null);
$res = file_put_contents("memory.txt",json_encode($memory));
if($res===false)
{
	echo "failed to reset memory";
	return;
}
echo "success";
?>