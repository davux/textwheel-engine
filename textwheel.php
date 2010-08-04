<?php

/*
 * TextWheel 0.1
 *
 * let's reinvent the wheel one last time
 *
 * This library of code is meant to be a fast and universal replacement
 * for any and all text-processing systems written in PHP
 *
 * It is dual-licensed for any use under the GNU/GPL2 and MIT licenses,
 * as suits you best
 *
 * (c) 2009 Fil - fil@rezo.net
 * Documentation & http://zzz.rezo.net/-TextWheel-
 *
 * Usage: $wheel = new TextWheel(); echo $wheel->text($text);
 *
 */

class TextWheelRule {

	## rule description
	# optional
	var $priority = 0; # rule priority (rules are applied in ascending order)
		# -100 = application escape, +100 = application unescape
	var $name; # rule's name
	var $author; # rule's author
	var $url; # rule's homepage
	var $package; # rule belongs to package
	var $version; # rule version
	var $test; # rule test function
	var $disabled=false; # true if rule is disabled

	## rule init checks
	## the rule will be applied if the text...
	# optional
	var $if_chars; # ...contains one of these chars
	var $if_str; # ...contains this string (case insensitive)
	var $if_match; # ...matches this simple expr


	## rule effectors, matching
	# mandatory
	var $type; # 'preg' (default), 'str', 'all'...
	var $match; # matching string or expression
	# optional
	# var $limit; # limit number of applications (unused)

	## rule effectors, replacing
	# mandatory
	var $replace; # replace match with this expression

	# optional
	var $is_wheel; # flag to create a sub-wheel from rules given as replace
	var $is_callback=false; # $replace is a callback function

	# optional
	# language specific
	var $require; # file to require_once
	var $create_replace; # do create_function('$m', %) on $this->replace, $m is the matched array

	# optimizations
	var $func_replace;
	
	public function TextWheelRule($args) {
		if (!is_array($args))
			return;
		foreach($args as $k=>$v)
			if (property_exists($this, $k))
				$this->$k = $args[$k];
	}
}

abstract class TextWheelDataSet {
	# list of datas
	protected $datas = array();
	
	/**
	 * Load a yaml file describing datas
	 * @param string $file
	 * @return array
	 */
	protected function loadFile($file, $default_path='') {
		if (!$default_path)
			$default_path = dirname(__FILE__).'/../wheels/';
		if (!preg_match(',[.]yaml$,i',$file)
			// external rules
			OR
				(!file_exists($file)
				// rules embed with texwheels
				AND !file_exists($file = $default_path.$file)
				)
			)
			return array();

		$datas = false;
		// yaml caching
		if (defined('_TW_DIR_CACHE_YAML')
			AND $hash = substr(md5($file),0,8)."-".substr(md5_file($file),0,8)
			AND $fcache = _TW_DIR_CACHE_YAML."yaml-".basename($file,'.yaml')."-".$hash.".txt"
			AND file_exists($fcache)
			AND $c = file_get_contents($fcache)
			)
			$datas = unserialize($c);

		if (!$datas){
			require_once dirname(__FILE__).'/../lib/yaml/sfYaml.php';
			$datas = sfYaml::load($file);
		}

		if (!$datas)
			return array();

		// if a php file with same name exists
		// include it as it contains callback functions
		if ($f = preg_replace(',[.]yaml$,i','.php',$file)
		  AND file_exists($f))
			include_once $f;

		if ($fcache AND !$c)
		 file_put_contents ($fcache, serialize($datas));
		
		return $datas;
	}

}

class TextWheelRuleSet extends TextWheelDataSet {
	# sort flag
	protected $sorted = true;

	/**
	 * Constructor
	 *
	 * @param array/string $ruleset
	 */
	public function TextWheelRuleSet($ruleset = array()) {
		if ($ruleset)
			$this->addRules($ruleset);
	}

	/**
	 * Get an existing named rule in order to override it
	 *
	 * @param string $name
	 * @return string
	 */
	public function &getRule($name){
		if (isset($this->datas[$name]))
			return $this->datas[$name];
		$result = null;
		return $result;
	}
	
	/**
	 * get sorted Rules
	 * @return array
	 */
	public function &getRules(){
		$this->sort();
		return $this->datas;
	}

	/**
	 * add a rule
	 *
	 * @param TextWheelRule $rule
	 */
	public function addRule($rule) {
		# cast array-rule to object
		if (is_array($rule))
			$rule = new TextWheelRule($rule);
		$this->datas[] = $rule;
		$this->sorted = false;
	}

	/**
	 * add an list of rules
	 * can be
	 * - an array of rules
	 * - a string filename
	 * - an array of string filename
	 *
	 * @param array/string $rules
	 */
	public function addRules($rules) {
		// rules can be an array of filename
		if (is_array($rules) AND is_string(reset($rules))) {
			foreach($rules as $i=>$filename)
				$this->addRules($filename);
			return;
		}

		// rules can be a string : yaml filename
		if (is_string($rules))
			$rules = $this->loadFile($rules);

		// rules can be an array of rules
		if (is_array($rules) AND count($rules)){
			# cast array-rules to objects
			foreach ($rules as $i => $rule)
				if (is_array($rule))
					$rules[$i] = new TextWheelRule($rule);
			$this->datas = array_merge($this->datas, $rules);
			$this->sorted = false;
		}
	}

	/**
	 * Sort rules according to priority and
	 */
	protected function sort() {
		if (!$this->sorted) {
			$rulz = array();
			foreach($this->datas as $index => $rule)
				$rulz[intval($rule->priority)][$index] = $rule;
			ksort($rulz);
			$this->datas = array();
			foreach($rulz as $rules)
				$this->datas += $rules;

			$this->sorted = true;
		}
	}
}

class TextWheel {
	protected $ruleset;
	protected static $subwheel = array();

	/**
	 * Constructor
	 * @param TextWheelRuleSet $ruleset
	 */
	public function TextWheel($ruleset = null) {
		$this->setRuleSet($ruleset);
	}

	/**
	 * Set RuleSet
	 * @param TextWheelRuleSet $ruleset
	 */
	public function setRuleSet($ruleset){
		if (!is_object($ruleset))
			$ruleset = new TextWheelRuleSet ();
		$this->ruleset = $ruleset;
	}

	/**
	 * Apply all rules of RuleSet to a text
	 *
	 * @param string $t
	 * @return string
	 */
	public function text($t) {
		$rules = & $this->ruleset->getRules();
		## apply each in order
		foreach ($rules as $i=>$rule) #php4+php5
			TextWheel::apply($rules[$i], $t);
		#foreach ($rules as &$rule) #smarter &reference, but php5 only
		#	TextWheel::apply($rule, $t);
		return $t;
	}

	/**
	 * Get an internal global subwheel
	 * read acces for annymous function only
	 *
	 * @param int $n
	 * @return TextWheel
	 */
	public static function &getSubWheel($n){
		return TextWheel::$subwheel[$n];
	}

	/**
	 * Initializing a rule a first call
	 * including file, creating function or wheel
	 * optimizing tests
	 *
	 * @param TextWheelRule $rule
	 */
	protected static function initRule(&$rule){

		# /begin optimization needed
		# language specific
		if ($rule->require)
			require_once $rule->require;
		if ($rule->create_replace){
			$rule->replace = create_function('$m', $rule->replace);
			$rule->create_replace = false;
			$rule->is_callback = true;
		}
		elseif ($rule->is_wheel){
			$n = count(TextWheel::$subwheel);
			TextWheel::$subwheel[] = new TextWheel(new TextWheelRuleSet($rule->replace));
			$var = '$m[0]';
			if ($rule->type=='all' OR $rule->type=='str')
				$var = '$m';
			$code = 'return TextWheel::getSubWheel('.$n.')->text('.$var.');';
			$rule->replace = create_function('$m', $code);
			$rule->is_wheel = false;
			$rule->is_callback = true;
		}
		# /end

		# optimization
		$rule->func_replace = '';
		if (isset($rule->replace)) {
			switch($rule->type) {
				case 'all':
					$rule->func_replace = 'replace_all';
					break;
				case 'str':
					$rule->func_replace = 'replace_str';
					break;
				case 'preg':
				default:
					$rule->func_replace = 'replace_preg';
					break;
			}
			if ($rule->is_callback)
				$rule->func_replace .= '_cb';
		}
		if (!method_exists("TextWheel", $rule->func_replace)){
			$rule->disabled = true;
			$rule->func_replace = 'replace_identity';
		}
		# /end
	}

	/**
	 * Apply a rule to a text
	 *
	 * @param TextWheelRule $rule
	 * @param string $t
	 * @param int $count
	 */
	protected static function apply(&$rule, &$t, &$count=null) {
		if ($rule->disabled)
			return;

		if (isset($rule->if_chars) AND (strpbrk($t, $rule->if_chars) === false))
			return;

		if (isset($rule->if_str) AND (stripos($t, $rule->if_str) === false))
			return;
		
		if (isset($rule->if_match) AND !preg_match($rule->if_match, $t))
			return;

		if (!isset($rule->func_replace))
			TextWheel::initRule($rule);

		$func = $rule->func_replace;
		TextWheel::$func($rule->match,$rule->replace,$t,$count);
	}

	/**
	 * No Replacement function
	 * fall back in case of unknown method for replacing
	 * should be called max once per rule
	 * 
	 * @param mixed $match
	 * @param mixed $replace
	 * @param string $t
	 * @param int $count
	 */
	protected static function replace_identity(&$match,&$replace,&$t,&$count){
	}

	/**
	 * Static replacement of All text
	 * @param mixed $match
	 * @param mixed $replace
	 * @param string $t
	 * @param int $count
	 */
	protected static function replace_all(&$match,&$replace,&$t,&$count){
		# special case: replace \0 with $t
		#   replace: "A\0B" will surround the string with A..B
		#   replace: "\0\0" will repeat the string
		if (strpos($replace, '\\0')!==FALSE)
			$t = str_replace('\\0', $t, $replace);
		else
			$t = $replace;
	}

	/**
	 * Call back replacement of All text
	 * @param mixed $match
	 * @param mixed $replace
	 * @param string $t
	 * @param int $count
	 */
	protected static function replace_all_cb(&$match,&$replace,&$t,&$count){
		$t = $replace($t);
	}

	/**
	 * Static string replacement
	 *
	 * @param mixed $match
	 * @param mixed $replace
	 * @param string $t
	 * @param int $count
	 */
	protected static function replace_str(&$match,&$replace,&$t,&$count){
		if (!is_string($match) OR strpos($t,$match)!==FALSE)
			$t = str_replace($match, $replace, $t, $count);
	}

	/**
	 * Callback string replacement
	 *
	 * @param mixed $match
	 * @param mixed $replace
	 * @param string $t
	 * @param int $count
	 */
	protected static function replace_str_cb(&$match,&$replace,&$t,&$count){
		if (strpos($t,$match)!==FALSE)
			if (count($b = explode($match, $t)) > 1)
				$t = join($replace($match), $b);
	}

	/**
	 * Static Preg replacement
	 *
	 * @param mixed $match
	 * @param mixed $replace
	 * @param string $t
	 * @param int $count
	 */
	protected static function replace_preg(&$match,&$replace,&$t,&$count){
		$t = preg_replace($match, $replace, $t, -1, $count);
	}

	/**
	 * Callback Preg replacement
	 * @param mixed $match
	 * @param mixed $replace
	 * @param string $t
	 * @param int $count
	 */
	protected static function replace_preg_cb(&$match,&$replace,&$t,&$count){
		$t = preg_replace_callback($match, $replace, $t, -1, $count);
	}
}

class TextWheelDebug extends TextWheel {
	protected function timer($t='rien', $raw = false) {
		static $time;
		$a=time(); $b=microtime();
		// microtime peut contenir les microsecondes et le temps
		$b=explode(' ',$b);
		if (count($b)==2) $a = end($b); // plus precis !
		$b = reset($b);
		if (!isset($time[$t])) {
			$time[$t] = $a + $b;
		} else {
			$p = ($a + $b - $time[$t]) * 1000;
			unset($time[$t]);
			if ($raw) return $p;
			if ($p < 1000)
				$s = '';
			else {
				$s = sprintf("%d ", $x = floor($p/1000));
				$p -= ($x*1000);
			}
			return $s . sprintf("%.3f ms", $p);
		}
	}
}



/* stripos for php4 */
if (!function_exists('stripos')) {
	function stripos($haystack, $needle) {
		return strpos($haystack, stristr( $haystack, $needle ));
	}
}

if (!function_exists('strpbrk')) {
	function strpbrk($haystack, $char_list) {
    $result = strcspn($haystack, $char_list);
    if ($result != strlen($haystack)) {
        return $result;
    }
    return false;
	}
}