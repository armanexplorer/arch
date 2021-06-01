<?php
$my_obj = new stdClass();
$my_obj->run_time_err=null;
$my_obj->src_err=null;

function myErrorHandler($code, $str, $errfile, $line)
{
	global $my_obj;
    $my_obj->run_time_err = "<br/>file : $errfile <br/> in line $line : $str";
    $my_obj->src_err = "asm";
    echo json_encode($my_obj);
    die();
}
function myErrorHandler2($code, $str, $errfile, $line)
{
	global $my_obj;
    $my_obj->run_time_err = "<br/>file : $errfile <br/> in line $line : $str";
    $my_obj->src_err = "mac";
    echo json_encode($my_obj);
    die();
}

set_error_handler("myErrorHandler");

////////////////////////////// DEFINE VARIABLES ////////////////////////////////

$memory_file = "memory.txt";
$registers_file = "registers.txt";
$use_reg_15_as_pc = false;
$url = isset($_POST['asm'])?$_POST['asm']:"test3.asm";
$memory = json_decode(file_get_contents($memory_file));
$not_fetch = array();
$not_change = array();
$zero_32bit = str_pad('0',32,'0');
$pc = 0;

$opcodes = array(
		"add"  => "0000",
		"sub"  => "0001",
		"slt"  => "0010",
		"or"   => "0011",
		"nand" => "0100",
		"addi" => "0101",
		"slti" => "0110",
		"ori"  => "0111",
		"lui"  => "1000",
		"lw"   => "1001",
		"sw"   => "1010",
		"beq"  => "1011",
		"jalr" => "1100",
		"j"    => "1101",
		"halt" => "1110"
);
$optype = array(
		"add"  => "R",
		"sub"  => "R",
		"slt"  => "R",
		"or"   => "R",
		"nand" => "R",
		"addi" => "I",
		"slti" => "I",
		"ori"  => "I",
		"lui"  => "I",
		"lw"   => "I",
		"sw"   => "I",
		"beq"  => "I",
		"jalr" => "I",
		"j"    => "J",
		"halt" => "J"
);

////////////////////////////// DEFINE FUNCTIONS ////////////////////////////////

function decToBin($dec)
{
	return ($dec<0?substr(decbin($dec),-32,32):str_pad(decbin($dec),32,'0',STR_PAD_LEFT));
}
function binToDec($x)
{
	return ($x[0]=='1'?-bindec(twoComp($x)):bindec($x));
}
function binExt($val,$sign)
{
	return str_pad($val,32,($sign & $val[0]),STR_PAD_LEFT);
}
function oneComp($x)
{
	return decToBin(~bindec($x));
}
function twoComp($x)
{
	return decToBin(~bindec($x)+1);
}
function binAdd($x,$y)
{
	return decToBin(binToDec($x)+binToDec($y));
}
function compile_asm_code($as_url)
{
	global $not_fetch,$not_change,$memory,$pc,$zero_32bit,$opcodes,$optype,$my_obj;
	$labels = array();
	$as_err = array();
	
	$setValue = function($str) use(&$labels , &$as_err)   /// Set Label Address Instead Of Label
	{  
		global $memory,$pc,$not_change;
		if(!is_numeric($str))
		{
		 	if(!array_key_exists($str, $labels))
		 	{

		 		$as_err[$pc] = "ERROR in line ".($pc+1)." : '$str' not found in labels!";
				$memory[$pc] = "ERR";
				array_push($not_change,$pc);
				$pc++;
				return false;
		 	}
			return $labels[$str];
		}
		return $str;
	};

	function decToBin4bit($dec)
	{
		global $as_err,$memory,$pc,$not_change;
		if(!is_numeric($dec) || $dec<0 || $dec>15)
		{
			$as_err[$pc] = "ERROR in line ".($pc+1)." : Register '$dec' is unvalid!";
			$memory[$pc] = "ERR";
			array_push($not_change,$pc);
			$pc++;
			return false;
		}
		return str_pad(decbin($dec),4,'0',STR_PAD_LEFT);
	}

	function decToBin16bit($dec)
	{  
		global $as_err,$memory,$pc;
		$dec = (int)$dec;
		$bin = $dec<0?decbin(~(int)$dec+1):decbin($dec);
		if(strlen($bin)>16)
		{
			$as_err[$pc] = "ERROR in line ".($pc+1)." : offset or immidiate is more than 16 bit!";
			$memory[$pc] = "ERR";
			array_push($not_change,$pc);
			$pc++;
			return false;
		}
		
		$out =  ($dec<0?substr(decbin($dec),-16,16):str_pad($bin,16,'0',STR_PAD_LEFT));
		return $out;
	}

	$as_file = fopen($as_url, "r") or die("Unable to open '$as_url'");
	$data = fread($as_file,filesize($as_url));          
	$lines = explode("\n",$data);
	$my_obj->asm_ins = $lines;
	
	foreach ($lines as $line)                   /// Set address labels and do directives
	{
		if(preg_match('/^(\s*)$/', $line))		/// Check If Line Is Empty
		{
			$pc++;
			continue;
		}
		if(!ctype_space(substr($line, 0,1)))	/// Label Lines
		{
			$label = preg_split('/\s+/',$line)[0];  /// Save First Word Before Spcae In $label
			if(array_key_exists($label, $labels))
				$as_err[$pc] = "WARNING in line ".($pc+1)." : The '$label' label has defined before!";
			$labels[$label] = $pc;
			$line = ltrim($line,$label);  		/// Remove Label From Left
		}
		$lines[$pc] = trim($line);
		$pc++;
	}
	$pc = 0;
	foreach ($lines as $line)	/// Directive instructions
	{
		$parts = preg_split('/\s+/', $line);
		//echo print_r($parts);
		if($parts[0]===".fill")
		{
			$val = $setValue($parts[1]);
			if($val===false)
				continue;
			$memory[$pc] = decToBin($val);
			array_push($not_fetch,$pc);
			$pc++;
			continue;
		}
		else if($parts[0]===".space")
		{
			$memory[$pc] = $zero_32bit;
			array_push($not_fetch,$pc);
			$pc++;
			continue;
		}
		$pc++;	
	}
	$pc = 0;
	foreach ($lines as $line)           		/// Start to compile line by line 
	{
		if(preg_match('/^(\s*)$/', $line))   	/// Check If Line Is Empty
		{
			$pc++;
			continue;
		}
		if(array_key_exists($pc, $as_err))
		{
			$pc++;
			continue;
		}
		
		$parts = preg_split('/\s+/', $line);  /// Separate Instruction To Two Parts(except 'halt')
		$op = $parts[0];

		if($op===".fill" || $op===".space")
		{
			$pc++;
			continue;
		}

		if(!array_key_exists($op, $opcodes))
		{
			$as_err[$pc] = "ERROR in line ".($pc+1)." : The Operation '$op' Is NOT Valid";
			$memory[$pc] = "ERR";
			array_push($not_change,$pc);
			$pc++;
			continue;
		}

		$my_size = sizeof($parts);
		$syn_err = false;
		if($op!="halt" && ($my_size<2 || ($my_size>2 && !preg_match('/^(#.*)?$/',$parts[2]))) )
			$syn_err = true;
		if($op==="halt" && ($my_size!=1 && !preg_match('/^(#.*)?$/',$parts[1])))
			$syn_err = true;
		if($syn_err)
		{
			$as_err[$pc] = "ERROR in line ".($pc+1)." : Syntax Error";
			$memory[$pc] = "ERR";
			array_push($not_change,$pc);
			$pc++;
			continue;
		}		
		if($optype[$op]==="J")
		{
			$tar_add = "0"; 	 /// For 'halt' operation
			if($op==="j")
				$tar_add = $setValue($parts[1]);	
			if($tar_add===false)
				continue;
			$tar_add = decToBin16bit($tar_add);
			if($tar_add===false)
				continue;
			$memory[$pc] = "0000{$opcodes[$op]}00000000{$tar_add}";
			array_push($not_change,$pc);
			$pc++;
			continue;
		}
		$parts = explode(',', $parts[1]);
		if(sizeof($parts)!=3)
		{
			$as_err[$pc] = "ERROR in line ".($pc+1)." : Syntax Error";
			$memory[$pc] = "ERR";
			array_push($not_change,$pc);
			$pc++;
			continue;
		}
		if($optype[$op]==="R")
		{
			$rd = decToBin4bit($parts[0]);
			$rs = decToBin4bit($parts[1]);
			$rt = decToBin4bit($parts[2]);
			if($rt===false || $rs===false || $rd===false || $rt==="0000")
				continue;
			$memory[$pc] = "0000{$opcodes[$op]}{$rs}{$rt}{$rd}000000000000";
			array_push($not_change,$pc);
		}
		else if($optype[$op]==="I")
		{
			$rs=$off_imm = "0";  /// For 'lui' And 'jalr' Operations
			$rt = $parts[0];     /// Is Common Field In I-Type
			if($op==="lui")
				$off_imm = $parts[1];
			else if($op==="jalr")
				$rs = $parts[1];
			else
			{
				$rs = $parts[1];
				$off_imm = $parts[2];
			}
			$off_imm = $setValue($off_imm);
			if($off_imm===false)
				continue;
			if(strlen(base_convert($off_imm,10,2))>16)
			{
				$as_err[$pc] = "ERROR in line ".($pc+1)." : offset or immidiate is more than 16 bit!";
				$memory[$pc] = "ERR";
				array_push($not_change,$pc);
				$pc++;
				continue;
			}
			if($op==="beq")  	/// User Intered Abs Address But We Must Convert It To Relative
				$off_imm-=($pc+1);
			$off_imm = decToBin16bit($off_imm);
			$rs = decToBin4bit($rs);
			$rt = decToBin4bit($rt);
			if($rt===false || $rs===false || $off_imm===false)
				continue;
			$memory[$pc] = "0000{$opcodes[$op]}{$rs}{$rt}{$off_imm}";
			array_push($not_change,$pc);
		}
		$pc++;
	}
	$memory[$pc] = "END";
	array_push($not_change,$pc);
	$my_obj->asm_errs = $as_err;
}

/////////////////////////////// DEFINE CLASSES /////////////////////////////////

$off_reg_write = array($opcodes["halt"],$opcodes["j"],$opcodes["beq"],$opcodes["sw"]);
class Control
{
	public $alu_op,$alu_src,$reg_dst,$jump,$branch,$mem_to_reg,$mem_read,$mem_write,$reg_write;
	public $halt,$sign_ext;
    public function set($op)
    {
    	global $opcodes,$optype,$off_reg_write;
    	$this->alu_op = array_search($op,$opcodes);
    	$this->alu_src = $optype[$this->alu_op]=="R"||$opcodes["beq"]==$op?0:1;
     	$this->reg_dst = $optype[$this->alu_op]=="R"?1:0;
     	$this->jump = $opcodes["j"]==$op?1:0;
     	$this->branch = $opcodes["beq"]==$op?1:0;
     	$this->mem_to_reg = $opcodes["lw"]==$op?1:0;
     	$this->mem_read = $opcodes["lw"]==$op?1:0;
     	$this->mem_write = $opcodes["sw"]==$op?1:0;
     	$this->reg_write = in_array($op,$off_reg_write)?0:1;
     	$this->halt = $opcodes["halt"]==$op?1:0;
     	$this->jalr = $opcodes["jalr"]==$op?1:0;
     	$this->sign_ext = $opcodes["ori"]==$op?0:1;
    }
}
class Alu
{
	public $op,$x,$y,$zero;
	public function set($op,$opr1,$opr2)
	{
		$this->op = $op;
		$this->x = $opr1;
		$this->y = $opr2;
		$this->zero = ($opr1-$opr2==0);
	}
	public function getRes()
	{
		switch ($this->op)
		{
			case "add":
			case "addi":
			case "lw":
			case "sw":
				return binAdd($this->x,$this->y);
			case "sub":
				return binAdd($this->x,twoComp($this->y));
			case "or":
			case "ori":
				return $this->x | $this->y;
			case "nand":
				return oneComp($this->x & $this->y);
			case "slt":
			case "slti":
				return decToBin(binToDec($this->x)<binToDec($this->y));
			case "lui":
				return decToBin(binToDec($this->x) << 16);
			default:
				return null;
		}
	}
	public function getZero()
	{
		return $this->zero;
	}
}

//////////////////////////// COMPILE ASSEMBLY CODE /////////////////////////////

compile_asm_code($url);

////////////////////////////// SET NEW ERROR HANDLER ///////////////////////////

set_error_handler("myErrorHandler2");

///////////////////// GET READY FOR EXECUTE MACHINE CODES //////////////////////

$mac_err=null;
$mac_err_cycle =-1; 
$reg = array();
$reg[0]=json_decode(file_get_contents($registers_file));
$cycle = 1;
$pc = 0;
$pc_in_cycle = array();
$change_in_mem = array();
$changed_reg = array();
$num_use_in_read = array_fill(0,16,0);
$num_use_in_write = array_fill(0,16,0);
$control = new Control();
$alu = new Alu();

//////////////////////// START EXECUTING MACHINE CODES /////////////////////////

while(true)
{
	//////////////////////////// DETECT RUNTIME ERROR //////////////////////////
	//echo "$cycle // $pc \n";
	if($cycle>10000)
	{
		$mac_err =  "TimeOut Error,Probably The Program Falled Into Loop!\n";
		$mac_err_cycle = $cycle;
		$cycle--;
		break;
	}

	///////////////// GET READY FOR STORE DETAILS OF RUNNING ///////////////////

	$pc_in_cycle[$cycle] = $pc;
	$reg[$cycle] = array_slice($reg[$cycle-1],0);
	$change_in_mem[$cycle] = array();
	$changed_reg[$cycle] = null;

	////////////////////////////////// FETCH ///////////////////////////////////

	if(strlen(decbin($pc))>16)
	{

		$mac_err =  "ERROR in cycle $cycle : The address for pc is out of range(pc=$pc)\n";
		$mac_err_cycle = $cycle;
		break;
	}
	if(in_array($pc,$not_fetch))
	{
		$change_in_mem[$cycle][$pc] = $memory[$pc];
		$pc++;
		$cycle++;
		continue;
	}

	$line = $memory[$pc];

	////////////////////////// HANDLE SOME EXCEPTINOS //////////////////////////

	if(preg_match('/^(\s*)$/', $line))		/// Check If Line Is Empty
	{
		$pc++;
		$cycle++;
		continue;
	}
	else if($line=='ERR')					/// Check If There Is Asm Error
	{
		$pc++;
		$cycle++;
		continue;
	}
	else if($line=='END')
	{
		$mac_err = "The program received to the end of instructions with no halt!";
		$mac_err_cycle = $cycle;
		break;
	}
	
	////////////////////////////////// DECODE //////////////////////////////////

	$op_code = substr($line,4,4);
	$read_reg1 = substr($line,8,4);
	$read_reg2 = substr($line,12,4);
	$read_reg3 = substr($line,16,4);
	$off_imm = substr($line,-16,16);
	$tar_add = substr($line,-16,16);

	////////////////////////// GET READY FOR EXCECUTE //////////////////////////

	$control->set($op_code);

	if($control->halt)
		break;

	$dec_read_reg1 = binToDec($read_reg1);
	$dec_read_reg2 = binToDec($read_reg2);
	$read_data1 = $reg[$cycle][$dec_read_reg1];
	$read_data2 = $reg[$cycle][$dec_read_reg2];
	$num_use_in_read[$dec_read_reg1]++;
	$num_use_in_read[$dec_read_reg2]++;

	$write_back_reg = binToDec($control->reg_dst?$read_reg3:$read_reg2);
	$mem_write_data = $read_data2;

	///////////////////////////// ALU SET AND GET //////////////////////////////

	$alu_op1 = $read_data1;
	$alu_op2 = $control->alu_src?binExt($off_imm,$control->sign_ext):$read_data2;
	$alu_operation = $control->alu_op;
	$alu->set($alu_operation,$alu_op1,$alu_op2);
	$alu_result = $mem_address = $alu->getRes();

	////////////////////////////////// MEMORY //////////////////////////////////

	$out_of_range_address = (strlen(decbin(bindec($mem_address)))>16)?1:0;
	if(($control->mem_read || $control->mem_write) && $out_of_range_address)
	{
		$mac_err = "ERROR in cycle $cycle : The address read or write is out of range!\n";
		$mac_err_cycle = $cycle;
		break;
	}
	if($control->mem_read)
		$mem_read_data = $memory[bindec($mem_address)];
	if($control->mem_write)
	{
		$dec_mem_address = bindec($mem_address);
		if(in_array($dec_mem_address,$not_change))
		{
			$mac_err = "ERROR in cycle $cycle : You Can't Override Running Codes!\n";
			$mac_err_cycle = $cycle;
			break;
		}
		$memory[$dec_mem_address] = $mem_write_data;
		$change_in_mem[$cycle][$dec_mem_address] = $mem_write_data;
	}

	//////////////////////////////// WRITE BACK ////////////////////////////////

	$write_back_data = $control->mem_to_reg?$mem_read_data:$alu_result;
	if($control->reg_write)
	{
		if($write_back_reg==0)
		{
			$mac_err = "ERROR in cycle $cycle : The Register '0' can't be changed!\n";
			$mac_err_cycle = $cycle;
			break;
		}
		$reg[$cycle][$write_back_reg] = $write_back_data;
		$num_use_in_write[$write_back_reg]++;
		$changed_reg[$cycle] = $write_back_reg;
	}

	/////////////////////////// SET PC FOR NEXT FETCH //////////////////////////

	if($use_reg_15_as_pc && $write_back_reg==15)
		$pc = bindec($reg[$cycle][15]);
	else if($control->jalr)
	{
		$reg[$cycle][$write_back_reg] = decToBin($pc+1);
		$pc = bindec($read_data1);
	}
	else if($control->jump)
		$pc = bindec($tar_add);
	else if($control->branch && $alu->getZero())
		$pc = binToDec(binExt($off_imm,1))+$pc+1;
	else
		$pc++;

	////////////////////// THE INSTRUCTION WAS EXECUTED  ///////////////////////

	$cycle++;
}

////////////////////////////// STORE DATA IN FILE //////////////////////////////

file_put_contents($memory_file, json_encode($memory));
file_put_contents($registers_file,json_encode($reg[$cycle]));

//////////////////////////// CALCULATE MEMORY USAGE ////////////////////////////

$my_obj->used_mems = array_filter($memory);
$my_obj->mem_use = sizeof($my_obj->used_mems);

////////////////////////////// SEND DATA FOR SHOW //////////////////////////////

$my_obj->register = $reg;
$my_obj->memory = $change_in_mem;
$my_obj->pc = $pc_in_cycle;
$my_obj->num = $cycle;
$my_obj->read = $num_use_in_read;
$my_obj->write = $num_use_in_write;
$my_obj->changed_reg = $changed_reg;
$my_obj->mac_err = $mac_err;
$my_obj->mac_err_cycle = $mac_err_cycle;
echo json_encode($my_obj);

///////////////////////////////////// FINSH ////////////////////////////////////

exit(0);
?>
