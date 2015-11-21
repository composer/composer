<?php
//=============================================================================
//
// Copyright Francois Laupretre <automap@tekwire.net>
//
//   Licensed under the Apache License, Version 2.0 (the "License");
//   you may not use this file except in compliance with the License.
//   You may obtain a copy of the License at
//
//       http://www.apache.org/licenses/LICENSE-2.0
//
//   Unless required by applicable law or agreed to in writing, software
//   distributed under the License is distributed on an "AS IS" BASIS,
//   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//   See the License for the specific language governing permissions and
//   limitations under the License.
//
//=============================================================================
/**
* @copyright Francois Laupretre <automap@tekwire.net>
* @license http://www.apache.org/licenses/LICENSE-2.0 Apache License, V 2.0
* @category Automap
* @package Automap
*///==========================================================================

//=============================================================================
/**
* A map instance (created from an existing map file)
*
* When the PECL extension is not present, this class is instantiated when the
* map is loaded, and it is used by the autoloader.
*
* When the extension is present, this class is instantiated only when explicitely
* referenced and is not used by the autoloader.
*
* API status: Public
* Included in the PHK PHP runtime: Yes
* Implemented in the extension: No
*///==========================================================================

namespace Automap {

if (!class_exists('Automap\Map',false)) {

class Map
{
/** Runtime API version */

const VERSION = '3.1.0';

/** We cannot load maps older than this version */
 
const MIN_MAP_VERSION = '3.1.0';

/** Map files start with this string */

const MAGIC = "AUTOMAP  M\024\x8\6\3";// Magic value for map files

//--------------------------
/** The absolute path of the map file */

private $path;			

/** @var array(<key> => <target>)	The symbol table */

private $symbols;

/** @var array(<name> => <value>)	The map options */

private $options;

/** @var string The version of \Automap\Build\Creator that created the map file */

private $version;

/** @var string The minimum runtime version needed to understand the map file */

private $minVersion;

/** @var integer Load flags */

private $flags;

/** @var string Absolute base path */

private $basePath;

//-----
/**
* Construct a map object from an existing map file (real or virtual)
*
* @param string $path Path of the map file to read
* @param integer $flags Combination of Automap load flags (@see Automap)
* @param string Reserved for internal use (PHK). Never set this.
*/

public function __construct($path,$flags=0,$_bp=null)
{
	//$start_time = microtime(true);//TRACE
	$this->path = self::mkAbsolutePath($path);
	$this->flags = $flags;

	try {
		$buf = self::getMapHeader($this->path);

		//-- Check magic

		if (substr($buf,9,14)!=self::MAGIC) {
			if (strpos($buf,'$vendorDir')!==false) {
				$this->loadComposerClassmap();
				return;
			} else {
				echo "$buf\n";
				throw new \Exception('Bad Magic');
			}
		}

		//-- Check min runtime version required by map

		$this->minVersion = trim(substr($buf,24,12));	
		if (version_compare($this->minVersion,self::VERSION) > 0) {
			throw new \Exception($this->path.': Cannot understand this map.'.
				' Requires at least Automap version '.$this->minVersion);
		}

		//-- Check if the map format is not too old

		$this->version = trim(substr($buf,37,12));
		if (strlen($this->version)==0)
			throw new \Exception('Invalid (empty) map version');
		if (version_compare($this->version,self::MIN_MAP_VERSION) < 0)
			throw new \Exception('Cannot understand this map. Format too old.');

		//-- Check file size

		$sz = (int)substr($buf,62,8);
		if ($sz != filesize($this->path)) {
			throw new \Exception('Invalid file size ('
				.filesize($this->path)."), should be $sz");
		}

		//-- Check CRC

		if ($flags & Mgr::CRC_CHECK) {
			$crc = substr($buf,71,8);
			if ($crc!==hash('adler32',substr_replace(file_get_contents($this->path),'00000000',71,8)))
				throw new \Exception('CRC error');
		}

		//-- Read data

		//$start = microtime(true);//TRACE
		if (!is_null($_bp)) $GLOBALS['__bp'] = $_bp;
		$a = require($this->path);
		if (!is_null($_bp)) unset($GLOBALS['__bp']);

		$this->options = $a['options'];
		$this->symbols = $a['map'];
		$this->basePath = $a['info']['abs_bp'];
		//echo '<p>map read time: '.((microtime(true)-$start)*1000);//TRACE

		//echo '<p>__construct time: '.((microtime(true)-$start_time)*1000);//TRACE
	} catch (\Exception $e) {
		$this->symbols = array(); // No retry later
		throw new \Exception($path.': Cannot load map - '.$e->getMessage());
	}
}

//---------

private function loadComposerClassmap()
{
	$this->minVersion = self::VERSION;
	$this->version = self::MIN_MAP_VERSION;
	$this->options = array('base_path' => '../..');
	$this->basePath = dirname(dirname(dirname($this->path))).'/'; // baseDir
	$this->symbols = array();
	foreach(require($this->path) as $sym => $path) {
		$this->symbols[$sym.\Automap\Mgr::T_CLASS] = $path.\Automap\Mgr::F_SCRIPT;
	}
}

//---------
//-- Get map file header

private static function getMapHeader($path)
{
	if (($fp = fopen($path,'rb'))===false)
		throw new \Exception('Cannot open map file');
	if (($buf = fread($fp,80))===false)
		throw new \Exception('Cannot read map header');
	fclose($fp);
	return $buf;
}

//---------
// Check if a given file is a map file

public static function isMapFile($path)
{
	$buf = self::getMapHeader($path);
	return (substr($buf,9,14)===self::MAGIC);
}

//---------
/**
* Combines a type and a symbol in a 'key'
*
* Starting with version 3.0, Automap is fully case-sensitive. This allows for
* higher performance and cleaner code.
*
* Do not use this method (reserved for use by other Automap classes)
*
* @param string $type one of the 'T_' constants
* @param string $name The symbol value (case sensitive)
* @return string Symbol key
*/

public static function key($type,$name)
{
	return trim($name,'\\').$type;
}

//---------
/**
* Extracts the namespace from a symbol name
*
* The returned value has no leading/trailing separator.
*
* Do not use: access reserved for Automap classes
*
* @param string $name The symbol value (case sensitive)
* @return string Namespace. If no namespace, returns an empty string.
*/

public static function nsKey($name)
{
	$name = trim($name,'\\');
	$pos = strrpos($name,'\\');
	return (($pos!==false) ? substr($name,0,$pos) : '');
}

//---
// These utility functions return 'read-only' properties

public function path() { return $this->path; }
public function flags() { return $this->flags; }
public function options() { return $this->options; }
public function version() { return $this->version; }
public function minVersion() { return $this->minVersion; }
public function basePath() { return $this->basePath; }

//---

public function option($opt)
{
	return (isset($this->options[$opt]) ? $this->options[$opt] : null);
}

//---

public function symbolCount()
{
	return count($this->symbols);
}

//---
// The entry we are exporting must be in the symbol table (no check)

private function exportEntry($key)
{
	$entry = $this->symbols[$key];

	$a = array(
		'stype'		=> substr($key,-1),
		'symbol' 	=> substr($key,0,-1),
		'ptype'		=> substr($entry,-1),
		'path'		=> $apath=substr($entry,0,-1)
		);

	$a['rpath'] = (strpos($apath,$this->basePath)===0)
		? substr($apath,strlen($this->basePath)) : $apath;

	return $a;
}

//---

public function getTarget($type,$symbol)
{
	$key = self::key($type,$symbol);
	return ((isset($this->symbols[$key])) ? $this->symbols[$key] : false);
}

//---

public function getSymbol($type,$symbol)
{
	return (($this->getTarget($type,$symbol)===false)
		? false: $this->exportEntry(self::key($type,$symbol)));
}

//-------
/**
* Try to resolve a symbol using this map
*
* For performance reasons, we trust the map and don't check if the symbol is
* defined after loading the script/extension/package.
*
* @param string $type One of the \Automap\Mgr::T_xxx symbol types
* @param string Symbol name including namespace (no leading '\')
* @param integer $id Used to return the ID of the map where the symbol was found
* @return true if found, false if not found
*/

public function resolve($type,$name,&$id)
{
	if (($target = $this->getTarget($type,$name))===false) return false;

	//-- Found

	$ptype = substr($target,-1);
	$path = substr($target,0,-1);
	switch($ptype) {
		case Mgr::F_EXTENSION:
			if (!dl($path)) return false;
			break;

		case Mgr::F_SCRIPT:
			//echo("Loading script file : $path\n");//TRACE
			{ require($path); }
			break;

		case Mgr::F_PACKAGE:
			// Remove E_NOTICE messages if the test script is a package - workaround
			// to PHP bug #39903 ('__COMPILER_HALT_OFFSET__ already defined')
			// In case of embedded packages and maps, the returned ID corresponds to
			// the map where the symbol was finally found.
		
			error_reporting(($errlevel = error_reporting()) & ~E_NOTICE);
			$mnt = require($path);
			error_reporting($errlevel);
			$pkg = \PHK\_Mgr::instance($mnt);
			$id = $pkg->automapID();
			return Mgr::map($id)->resolve($type,$name,$id);
			break;

		default:
			throw new \Exception("<$ptype>: Unknown target type");
	}
	return true;
}

//---
/* Returns every entry converted to the export format */

public function symbols()
{
	$ret = array();
	foreach(array_keys($this->symbols) as $key) $ret[] = $this->exportEntry($key);

	return $ret;
}

//---
// Proxy to \Automap\Tools\Display::show()

public function show($format=null,$subfile_to_url_function=null)
{
	return Tools\Display::show($this,$format,$subfile_to_url_function);
}

//---
//TODO: Export/import options

public function export($path=null)
{
	if (is_null($path)) $path = "php://stdout";
	$fp = fopen($path,'w');
	if (!$fp) throw new \Exception("$path: Cannot open for writing");

	foreach($this->symbols() as $s) {
		fwrite($fp,$s['stype'].'|'.$s['symbol'].'|'.$s['ptype'].'|'.$s['rpath']."\n");
	}

	fclose($fp);
}

//---------------------------------
/**
* Transmits map elements to the PECL extension
*
* Reserved for internal use
*
* The first time a given map file is loaded, it is read by Automap\Map and
* transmitted to the extension. On subsequent requests, it is retrieved from
* persistent memory. This allows to code complex features in PHP and maintain
* the code in a single location without impacting performance.
*
* @param string $version The version of data to transmit (reserved for future use)
* @return array
*/

public function _peclGetMap($version)
{
	$st = array();
	foreach($this->symbols() as $s) {
		$st[] = array($s['stype'],$s['symbol'],$s['ptype'],$s['path']);
	}

	return $st;
}

//============ Utilities (taken from external libs) ============
// We need to duplicate these methods here because this class is included in the
// PHK PHP runtime, which does not include the \Phool\xxx classes.

//----- Taken from \Phool\File
/**
* Combines a base path with another path
*
* The base path can be relative or absolute.
*
* The 2nd path can also be relative or absolute. If absolute, it is returned
* as-is. If it is a relative path, it is combined to the base path.
*
* Uses '/' as separator (to be compatible with stream-wrapper URIs).
*
* @param string $base The base path
* @param string|null $path The path to combine
* @param bool $separ true: add trailing sep, false: remove it
* @return string The resulting path
*/

private static function combinePath($base,$path,$separ=false)
{
	if (($base=='.') || ($base=='') || self::isAbsolutePath($path))
		$res = $path;
	elseif (($path=='.') || is_null($path))
		$res = $base;
	else	//-- Relative path : combine it to base
		$res = rtrim($base,'/\\').'/'.$path;

	return self::trailingSepar($res,$separ);
}

/**
* Adds or removes a trailing separator in a path
*
* @param string $path Input
* @param bool $flag true: add trailing sep, false: remove it
* @return bool The result path
*/

private static function trailingSepar($path, $separ)
{
	$path = rtrim($path,'/\\');
	if ($path=='') return '/';
	if ($separ) $path = $path.'/';
	return $path;
}

/**
* Determines if a given path is absolute or relative
*
* @param string $path The path to check
* @return bool True if the path is absolute, false if relative
*/

private static function isAbsolutePath($path)
{
	return ((strpos($path,':')!==false)
		||(strpos($path,'/')===0)
		||(strpos($path,'\\')===0));
}

/**
* Build an absolute path from a given (absolute or relative) path
*
* If the input path is relative, it is combined with the current working
* directory.
*
* @param string $path The path to make absolute
* @param bool $separ True if the resulting path must contain a trailing separator
* @return string The resulting absolute path
*/

private static function mkAbsolutePath($path,$separ=false)
{
	if (!self::isAbsolutePath($path)) $path = self::combinePath(getcwd(),$path);
	return self::trailingSepar($path,$separ);
}

//---
} // End of class
//===========================================================================
} // End of class_exists
//===========================================================================
} // End of namespace
//===========================================================================
?>
