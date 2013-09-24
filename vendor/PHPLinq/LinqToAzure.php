<?php
/**
 * PHPLinq
 *
 * Copyright (c) 2008 - 2011 PHPLinq
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * 
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category   PHPLinq
 * @package    PHPLinq
 * @copyright  Copyright (c) 2008 - 2011 PHPLinq (http://www.codeplex.com/PHPLinq)
 * @license    http://www.gnu.org/licenses/lgpl.txt	LGPL
 * @version    ##VERSION##, ##DATE##
 */


/** PHPLinq */
require_once('PHPLinq.php');

/** PHPLinq_ILinqProvider */
require_once('PHPLinq/ILinqProvider.php');

/** PHPLinq_LinqToObjects */
require_once('PHPLinq/LinqToObjects.php');

/** PHPLinq_Expression */
require_once('PHPLinq/Expression.php');

/** PHPLinq_OrderByExpression */
require_once('PHPLinq/OrderByExpression.php');

/** PHPLinq_Initiator */
require_once('PHPLinq/Initiator.php');

/** Microsoft_WindowsAzure_Storage_Table */
require_once 'Microsoft/WindowsAzure/Storage/Table.php';

/** Register ILinqProvider */
PHPLinq_Initiator::registerProvider('PHPLinq_LinqToAzure');

/**
 * PHPLinq_LinqToAzure
 *
 * @category   PHPLinq
 * @package    PHPLinq
 * @copyright  Copyright (c) 2008 - 2011 PHPLinq (http://www.codeplex.com/PHPLinq)
 */
class PHPLinq_LinqToAzure implements PHPLinq_ILinqProvider {
	/** Constants */
	const T_FUNCTION 		= 1001001;
	const T_PROPERTY 		= 1001002;
	const T_CONSTANT 		= 1001003;
	const T_VARIABLE 		= 1001004;
	const T_OBJECT_OPERATOR = 1001005;
	const T_OPERATOR		= 1001006;
	const T_OPERAND 		= 1001007;
	const T_DEFAULT 		= 1001008;
	const T_START_STOP 		= 1001009;
	const T_SIMPLE			= 1001010;
	const T_ARGUMENT		= 1001011;
	const T_ARITHMETIC		= 1001012;
	
	/**
	 * Default variable name
	 *
	 * @var string
	 */
	private $_from = '';
	
	/**
	 * Data source
	 *
	 * @var mixed
	 */
	private $_data = null;
	
	/**
	 * Where expression
	 *
	 * @var PHPLinq_Expression
	 */
	private $_where = null;
	
	/**
	 * Take n elements
	 *
	 * @var int?
	 */
	private $_take = null;
	
	/**
	 * Skip n elements
	 *
	 * @var int?
	 */
	private $_skip = null;
	
	/**
	 * Take while expression is true
	 *
	 * @var PHPLinq_Expression
	 */
	private $_takeWhile = null;
	
	/**
	 * Skip while expression is true
	 *
	 * @var PHPLinq_Expression
	 */
	private $_skipWhile = null;
	
	/**
	 * OrderBy expressions
	 *
	 * @var PHPLinq_Expression[]
	 */
	private $_orderBy = array();
	
	/**
	 * Distinct expression
	 *
	 * @var PHPLinq_Expression
	 */
	private $_distinct = null;
	
	/**
	 * OfType expression
	 *
	 * @var PHPLinq_Expression
	 */
	private $_ofType = null;
	
	/**
	 * Parent PHPLinq_ILinqProvider instance, used with join conditions
	 *
	 * @var PHPLinq_ILinqProvider
	 */
	private $_parentProvider = null;
	
	/**
	 * Child PHPLinq_ILinqProvider instances, used with join conditions
	 *
	 * @var PHPLinq_ILinqProvider[]
	 */
	private $_childProviders = array();
	
	/**
	 * Join condition
	 *
	 * @var PHPLinq_Expression
	 */
	private $_joinCondition = null;
	
	/**
	 * Is object destructing?
	 *
	 * @var bool
	 */
	private $_isDestructing;
	
	/**
	 * Columns to select
	 *
	 * @var string
	 */
	private $_columns = '*';
	
	/**
	 * Storage client
	 *
	 * @var Microsoft_WindowsAzure_Storage_Table
	 */
	private $_storageClient = null;
	
	/**
	 * Static list of PHP internal functions used for generating queries
	 *
	 * @var array
	 */
	private static $_internalFunctions = null;
	
	/**
	 * Can this provider type handle data in $source?
	 *
	 * @param mixed $source
	 * @return bool
	 */
	public static function handles($source) {
		return (is_array($source) && count($source) >= 2 && $source[0] instanceof Microsoft_WindowsAzure_Storage_Table && $source[1] != '');
	}
	
	/**
	 * Create a new class instance
	 *
	 * @param string $name
	 * @param PHPLinq_ILinqProvider $parentProvider Optional parent PHPLinq_ILinqProvider instance, used with join conditions
	 * @return PHPLinq_ILinqProvider
	 */
	public function __construct($name, PHPLinq_ILinqProvider $parentProvider = null) {
		if (is_null(self::$_internalFunctions)) {
			$internalFunctions = get_defined_functions();
			$internalFunctions['internal'][] = 'print'; // Add as PHP function
			self::$_internalFunctions = $internalFunctions['internal'];
		}
		
		$this->_from = $name;
		
		if (!is_null($parentProvider)) {
			$this->_parentProvider = $parentProvider;
			$parentProvider->addChildProvider($this);
		}
		
		return $this;
	}
	
	/**
	 * Class destructor
	 */
	public function __destruct() {
		$this->_isDestructing = true;

		if (isset($this->_parentProvider) && !is_null($this->_parentProvider)) {
			if (!$this->_parentProvider->__isDestructing()) {
				$this->_parentProvider->__destruct();
			}
			$this->_parentProvider = null;
			unset($this->_parentProvider);
		}
		
		if (!is_null($this->_childProviders)) {
			foreach ($this->_childProviders as $provider) {
				$provider->__destruct();
				$provider = null;
				unset($provider);
			}
		}
	}

	/**
	 * Is object destructing?
	 *
	 * @return bool
	 */
	public function __isDestructing() {
		return $this->_isDestructing;
	}
	
	/**
	 * Get join condition
	 *
	 * @return PHPLinq_Expression
	 */
	public function getJoinCondition() {
		return $this->_joinCondition;
	}
	
	/**
	 * Add child provider, used with joins
	 *
	 * @param PHPLinq_ILinqProvider $provider
	 */
	public function addChildProvider(PHPLinq_ILinqProvider $provider) {
		$this->_childProviders[] = $provider;
	}
	
	/**
	 * Retrieve "from" name
	 *
	 * @return string
	 */
	public function getFromName() {
		return $this->_from;
	}
	
	/**
	 * Retrieve data in data source
	 *
	 * @return mixed
	 */
	public function getSource() {
		return $this->_data;
	}
	
	/**
	 * Set source of data
	 *
	 * @param mixed $source
	 * @return PHPLinq_ILinqProvider
	 */
	public function in($source) {
		$this->_data = $source;
		$this->_storageClient = $source[0];

		return $this;
	}

	/**
	 * Select
	 *
	 * @param  string	$expression	Expression which creates a resulting element
	 * @return mixed
	 */
	public function select($expression = null) {
		// Return value
		$returnValue = array();
		
		// Expression set?
		if (is_null($expression) || $expression == '') {
			$expression = $this->_from . ' => ' . $this->_from;
		}
		
		// Data source
		$dataSource = $this->_data[0]->select()->from($this->_data[1]);

		// Create selector expression
		$selector = new PHPLinq_Expression($expression, $this->_from);
		
		// Join set?
		if (count($this->_childProviders) > 0) {
			throw new PHPLinq_Exception('Joins are not supported in ' . __CLASS__ . '.');
		}
	
		// Where expresion set? Can Azure Table Storage run it? Evaluate it!
		if (!is_null($this->_where) && $this->_supportedByAzure($this->_where->getFunction()->getFunctionCode())) {
			$functionCode = $this->_convertToAzure(
				$this->_where->getFunction()->getFunctionCode()
			);
			
			$dataSource = $dataSource->where($functionCode);
		}
		
		// OrderBy set?
		/*if (is_array($this->_orderBy) && count($this->_orderBy) > 0) {
			$orderBy = array();
			foreach ($this->_orderBy as $orderByExpression) {
				if ($this->_supportedByAzure($orderByExpression->getExpression()->getFunction()->getFunctionCode())) {	
					$dataSource = $dataSource->orderBy(
						$this->_convertToAzure(
							$orderByExpression->getExpression()->getFunction()->getFunctionCode()
						), ($orderByExpression->isDescending() ? 'desc' : 'asc')
					);
				}
			}
		}*/

		// Query
		$results = null;
		if (isset($this->_data[2])) {
			$results = $this->_storageClient->retrieveEntities($dataSource, $this->_data[2]);
		} else {
			$results = $this->_storageClient->retrieveEntities($dataSource);
		}
		
		// OrderBy set?
		if (is_array($this->_orderBy) && count($this->_orderBy) > 0) {
			// Create sorter
			$sorter = '';
			
			// Is there only one OrderBy expression?
			if (count($this->_orderBy) == 1) {
				// First sorter
				$sorter = $this->_orderBy[0]->getFunctionReference();
			} else {
				// Create OrderBy expression
				$compareCode = '';
				
				// Compile comparer function
				$compareCode = "
					\$result = null;
				";
				for ($i = 0; $i < count($this->_orderBy); $i++) {
					
					$f = substr($this->_orderBy[$i]->getFunctionReference(), 1); 
					$compareCode .= "
					\$result = call_user_func_array(chr(0).'$f', array({$this->_from}A, {$this->_from}B));
					if (\$result != 0) {
						return \$result;
					}
					";
					
				}
				$compareCode .= "return \$result;";
				$sorter = create_function($this->_from . 'A, ' . $this->_from . 'B', $compareCode);
			}
			
			// Sort!
			usort($results, $sorter);
		}

		// Count elements
		$elementCount = 0;
		
		// Loop trough data source
		foreach ($results as $value) {
			// Is it a valid element?
			$isValid = true;
			
			// OfType expresion set? Evaluate it!
			if ($isValid && !is_null($this->_ofType)) {
				$isValid = $this->_ofType->execute($value);
			}
			
			// Where expresion set? Evaluate it!
			if ($isValid && !is_null($this->_where)) {
				$isValid = $this->_where->execute($value);
			}
			
			// Distinct expression set? Evaluate it!
			if ($isValid && !is_null($this->_distinct)) {
				$distinctKey = $this->_distinct->execute($value);
				if (isset($distinctValues[$distinctKey])) {
					$isValid = false;
				} else {
					$distinctValues[$distinctKey] = 1; 
				}
			}
					
			// The element is valid, check if it is our selection range
			if ($isValid) {
				// Skip element?
				if (!is_null($this->_skipWhile) && $this->_skipWhile->execute($value)) {
					$isValid = false;
				}
				if (!is_null($this->_skip) && $elementCount < $this->_skip) {
					$isValid = false;
				}
				
				// Take element?
				if (!is_null($this->_takeWhile) && !$this->_takeWhile->execute($value)) {
					$isValid = false;
					break;
				}
				if (!is_null($this->_take) && count($returnValue) >= $this->_take) {
					$isValid = false;
					break;
				}
				
				// Next element
				$elementCount++;

				// Add the element to the return value if it is a valid element
				if ($isValid) {
					$returnValue[] = $selector->execute($value);
				}
			}
		}

		// Return value
		return $returnValue;
	}
	
	/**
	 * PHP code supported by Azure?
	 *
	 * @param string $phpCode	Code to verify
	 * @return boolean
	 */
    private function _supportedByAzure($phpCode) {
		$supported = true;
		
		// Go parse!
        $tokens = token_get_all('<?php ' . $phpCode . '?>');
        foreach ($tokens as $token) {
			if (is_array($token) && $token[0] == T_STRING) {
				if (in_array($token[1], self::$_internalFunctions)) {
					return false;
				}
			}
		}
		
		// All is ok!
		return true;
	}
	
	/**
	 * Converts PHP code into Azure query code
	 *
	 * @param string $phpCode	Code to convert
	 * @return string
	 */
    private function _convertToAzure($phpCode) {
    	// Some fast cleaning
		$phpCode = str_replace('return ', '', $phpCode);
		$phpCode = str_replace(';', '', $phpCode);
		
		// Go parse!
        $tokens 		= token_get_all('<?php ' . $phpCode . '?>');
        $stack 			= array();
        $tokenId		= 0;
        $depth			= 0;

        for ($i = 0; $i < count($tokens); $i++) {
        	// Ignore token?
        	$ignoreToken	= false;
        	
        	// Token details
        	$previousToken 	= $i > 0 ? $tokens[$i - 1] : null;
            $token 			= $tokens[$i];
            $nextToken	 	= $i < count($tokens) - 1 ? $tokens[$i + 1] : null;
            
            // Parse token
            if (is_array($token)) {
                switch ($token[0]) {
                    case T_OPEN_TAG:
                    case T_CLOSE_TAG:
                    	$ignoreToken = true;
                    	
                        break;

                    case T_STRING:
                    	if (!in_array($token[1], self::$_internalFunctions)) {
                    		// Probably some sort of constant / property name
                    		if (!is_null($previousToken) && is_array($previousToken) && $previousToken[0] == T_OBJECT_OPERATOR) {
                    			$stack[$tokenId]['token'] = '\'' . $token[1] . '\'';
                    			$stack[$tokenId]['type']  = self::T_PROPERTY;
                    		} else {
die($token[1]);
                    			$stack[$tokenId]['token'] = '\'' . $token[1] . '\'';
                    			$stack[$tokenId]['type']  = self::T_CONSTANT;
                    		}
                    	}
                        
                        break;
                        
                    case T_VARIABLE:
                    case T_OBJECT_OPERATOR: 
                    	$ignoreToken = true;
                        
                        break;
                    
                    case T_IS_IDENTICAL:
                    case T_IS_EQUAL:
                        $stack[$tokenId]['token'] = '\' eq \'';
                        $stack[$tokenId]['type']  = self::T_OPERATOR;
                        
                        break;
                        
                    case T_IS_NOT_EQUAL:
                    case T_IS_NOT_IDENTICAL:
                        $stack[$tokenId]['token'] = '\' ne \'';
                        $stack[$tokenId]['type']  = self::T_OPERATOR;
                        
                        break;
                        
                    case T_IS_GREATER_OR_EQUAL:
                        $stack[$tokenId]['token'] = '\' ge \'';
                        $stack[$tokenId]['type']  = self::T_OPERATOR;

                        break;

                    case T_IS_SMALLER_OR_EQUAL:
                        $stack[$tokenId]['token'] = '\' le \'';
                        $stack[$tokenId]['type']  = self::T_OPERATOR;

                        break;
                    
                    case T_BOOLEAN_AND:
                    case T_LOGICAL_AND:
                        $stack[$tokenId]['token'] = '\' and \''; 
                        $stack[$tokenId]['type']  = self::T_OPERAND;
                        
                        break;
                        
                    case T_BOOLEAN_OR:    
                    case T_LOGICAL_OR:
                        $stack[$tokenId]['token'] = '\' or \'';
                        $stack[$tokenId]['type']  = self::T_OPERAND;

                        break;

                    default: 
                        $stack[$tokenId]['token'] = '\'' . str_replace('"', '\\\'', $token[1]) . '\'';
                        $stack[$tokenId]['type']  = self::T_DEFAULT;

                        break;
                }
            } else {
            	// Simple token
            	if ($token != '(' && $token != ')') {
            		$stack[$tokenId]['token'] = '\'' . $token . '\'';
            		$stack[$tokenId]['type']  = self::T_SIMPLE;
            		
            		if ($token == ',') {
            			$stack[$tokenId]['type']  = self::T_ARGUMENT;
            		} else if ($token == '+' || $token == '-' || $token == '*' || $token == '/' || $token == '%' || $token == '.') {
            			$stack[$tokenId]['token'] = '\'' . $token . '\'';
            			$stack[$tokenId]['type']  = self::T_ARITHMETIC;
            		} else if ($token == '>') {
            			$stack[$tokenId]['token'] = '\' gt \'';
            			$stack[$tokenId]['type']  = self::T_OPERATOR;
            		} else if ($token == '<') {
            			$stack[$tokenId]['token'] = '\' lt \'';
            			$stack[$tokenId]['type']  = self::T_OPERATOR;
            		}
            	} else {
	            	$stack[$tokenId]['token'] = '';
	            	$stack[$tokenId]['type']  = self::T_START_STOP;
            	}
            }

            // Token metadata
            if (!$ignoreToken) {
	            // Depth
	            if (!is_array($token) && $token == '(') $depth++;
	            if (!is_array($token) && $token == ')') $depth--;	
	            $stack[$tokenId]['depth'] = $depth;         
	            
	            // Identifier
	            $tokenId++;
            }
        }
        
        // Display tree
        /*
        foreach ($stack as $node) {
        	for ($i = 0; $i <= $node['depth']; $i++) {
        		echo "| ";
        	}
        	
        	echo $node['type'] . ' - ' . $node['token'] . "\r\n";
        }
        die();
        */

        // Build compilation string
        $compileCode 	= '';
        $functionDepth	= array();
        $depth 			= 0;
        for ($i = 0; $i < count($stack); $i++) {
        	// Token details
        	$previousToken 	= $i > 0 ? $stack[$i - 1] : null;
            $token 			= $stack[$i];
            $nextToken	 	= $i < count($stack) - 1 ? $stack[$i + 1] : null;  
            
        	// Regular token
        	if ($token['depth'] == $depth && $token['type'] != self::T_START_STOP) {
        		$compileCode .= $token['token'];
        	}
        	
        	// Start/stop
        	if ($token['depth'] > $depth && $token['type'] == self::T_START_STOP && !is_null($previousToken) && $previousToken['type'] == self::T_FUNCTION) {
        		$compileCode .= '(';
        		$functionDepth[$depth] = self::T_FUNCTION;
        	} else if ($token['depth'] < $depth && $token['type'] == self::T_START_STOP && $functionDepth[ $token['depth'] ] == self::T_FUNCTION) {
        		$compileCode .= ')';
        	} else if ($token['depth'] > $depth && $token['type'] == self::T_START_STOP) {
        		$compileCode .= '\'(\'';
        		$functionDepth[$depth] = self::T_START_STOP;
        	} else if ($token['depth'] < $depth && $token['type'] == self::T_START_STOP) {
        		$compileCode .= '\')\'';
        	}
        	
        	// Next token needs concatenation?
        	if (!is_null($nextToken) && $nextToken['type'] != self::T_ARGUMENT) {
        		if (
        			!($token['type'] == self::T_FUNCTION && $nextToken['type'] == self::T_START_STOP) &&
        			!(!is_null($previousToken) && $previousToken['type'] == self::T_FUNCTION && $token['type'] == self::T_START_STOP) &&
        			
        			!($token['type'] == self::T_ARGUMENT) &&
        			
        			!(!is_null($nextToken) && $nextToken['type'] == self::T_START_STOP && isset($functionDepth[ $nextToken['depth'] ]) && $functionDepth[ $nextToken['depth'] ] == self::T_FUNCTION) &&
        			
        			!($token['type'] == self::T_VARIABLE && !is_null($nextToken) && $nextToken['type'] == self::T_PROPERTY)
        			) {
        			$compileCode .= ' . ';
        		}
        	}

        	// Depth
        	if ($token['depth'] < $depth) {
        		unset($functionDepth[$token['depth']]);
        	}
        	$depth = $token['depth'];
        }

        // Compile
		$compileCode = '$compileCode = ' . $compileCode . ';';
		eval($compileCode);

        return $compileCode;
    }
	
	/**
	 * Where
	 *
	 * @param  string	$expression	Expression checking if an element should be contained
	 * @return PHPLinq_ILinqProvider
	 */
	public function where($expression) {
		$this->_where = !is_null($expression) ? new PHPLinq_Expression($expression, $this->_from) : null;
		return $this;
	}
	
	/**
	 * Take $n elements
	 *
	 * @param int $n
	 * @return PHPLinq_ILinqProvider
	 */
	public function take($n) {
		$this->_take = $n;
		return $this;
	}
	
	/**
	 * Skip $n elements
	 *
	 * @param int $n
	 * @return PHPLinq_ILinqProvider
	 */
	public function skip($n) {
		$this->_skip = $n;
		return $this;
	}
	
	/**
	 * Take elements while $expression evaluates to true
	 *
	 * @param  string	$expression	Expression to evaluate
	 * @return PHPLinq_ILinqProvider
	 */
	public function takeWhile($expression) {
		$this->_takeWhile = !is_null($expression) ? new PHPLinq_Expression($expression, $this->_from) : null;
		return $this;
	}
	
	/**
	 * Skip elements while $expression evaluates to true
	 *
	 * @param  string	$expression	Expression to evaluate
	 * @return PHPLinq_ILinqProvider
	 */
	public function skipWhile($expression) {
		$this->_skipWhile = !is_null($expression) ? new PHPLinq_Expression($expression, $this->_from) : null;
		return $this;
	}
	
	/**
	 * OrderBy
	 *
	 * @param  string	$expression	Expression to order elements by
	 * @param  string	$comparer	Comparer function (taking 2 arguments, returning -1, 0, 1)
	 * @return PHPLinq_ILinqProvider
	 */
	public function orderBy($expression, $comparer = null) {
		$this->_orderBy[0] = new PHPLinq_OrderByExpression($expression, $this->_from, false, $comparer);
		return $this;
	}
	
	/**
	 * OrderByDescending
	 *
	 * @param  string	$expression	Expression to order elements by
	 * @param  string	$comparer	Comparer function (taking 2 arguments, returning -1, 0, 1)
	 * @return PHPLinq_ILinqProvider
	 */
	public function orderByDescending($expression, $comparer = null) {
		$this->_orderBy[0] = new PHPLinq_OrderByExpression($expression, $this->_from, true, $comparer);
		return $this;
	}
	
	/**
	 * ThenBy
	 *
	 * @param  string	$expression	Expression to order elements by
	 * @param  string	$comparer	Comparer function (taking 2 arguments, returning -1, 0, 1)
	 * @return PHPLinq_ILinqProvider
	 */
	public function thenBy($expression, $comparer = null) {
		$this->_orderBy[] = new PHPLinq_OrderByExpression($expression, $this->_from, false, $comparer);
		return $this;
	}
	
	/**
	 * ThenByDescending
	 *
	 * @param  string	$expression	Expression to order elements by
	 * @param  string	$comparer	Comparer function (taking 2 arguments, returning -1, 0, 1)
	 * @return PHPLinq_ILinqProvider
	 */
	public function thenByDescending($expression, $comparer = null) {
		$this->_orderBy[] = new PHPLinq_OrderByExpression($expression, $this->_from, true, $comparer);
		return $this;
	}
	
	/**
	 * Distinct
	 *
	 * @param  string	$expression	Ignored. 
	 * @return PHPLinq_ILinqProvider
	 */
	public function distinct($expression) {
		$this->_distinct = !is_null($expression) ? new PHPLinq_Expression($expression, $this->_from) : null;
		return $this;
	}
	
	/**
	 * Select the elements of a certain type
	 *
	 * @param string $type	Type name
	 */
	public function ofType($type) {
		// Create a new expression
		$expression = $this->_from . ' => ';
		
		// Evaluate type
		switch (strtolower($type)) {
			case 'array':
			case 'bool':
			case 'double':
			case 'float':
			case 'int':
			case 'integer':
			case 'long':
			case 'null':
			case 'numeric':
			case 'object':
			case 'real':
			case 'scalar':
			case 'string':
				$expression .= 'is_' . strtolower($type) . '(' . $this->_from . ')';
				break;
			default:
				$expression .= 'is_a(' . $this->_from . ', "' . $type . '")';
				break;
		}
		
		// Assign expression
		$this->_ofType = new PHPLinq_Expression($expression, $this->_from);
		return $this;
	}
	
	/**
	 * Any
	 *
	 * @param  string	$expression	Expression checking if an element is contained
	 * @return boolean
	 */
	public function any($expression) {
		$originalWhere = $this->_where;
		
		$result = $this->where($expression)->select($this->_from);
		
		$this->_where = $originalWhere;
		
		return count($result) > 0;
	}
	
	/**
	 * All
	 *
	 * @param  string	$expression	Expression checking if an all elements are contained
	 * @return boolean
	 */
	public function all($expression) {
		$originalWhere = $this->_where;
		
		$result = $this->where($expression)->select($this->_from);
		
		$this->_where = $originalWhere;
		
		return count($result) == count($this->_data);
	}

	/**
	 * Contains - Not performed as query! (heavy)
	 *
	 * @param mixed $element Is the $element contained?
	 * @return boolean
	 */
	public function contains($element) {
		return in_array($element, $this->select());
	}
	
	/**
	 * Reverse elements - Not performed as query! (heavy)
	 * 
	 * @param bool $preserveKeys Preserve keys?
	 * @return PHPLinq_ILinqProvider
	 */
	public function reverse($preserveKeys = null) {
		$data = array_reverse($this->select(), $preserveKeys);
		return linqfrom($this->_from)->in($data);
	}
	
	/**
	 * Element at index
	 *
	 * @param mixed $index Index
	 * @return mixed Element at $index
	 */
	public function elementAt($index = null) {
		$originalWhere = $this->_where;
		
		$result = $this->where(null)->take(1)->skip($index)->select();
		
		$this->_where = $originalWhere;
		
		if (count($result) > 0) {
			return array_shift($result);
		}
		return null;
	}
	
	/**
	 * Element at index or default
	 *
	 * @param mixed $index Index
	 * @param  mixed $defaultValue Default value to return if nothing is found
	 * @return mixed Element at $index
	 */
	public function elementAtOrDefault($index = null, $defaultValue = null) {
		$returnValue = $this->elementAt($index);
		if (!is_null($returnValue)) {
			return $returnValue;
		} else {
			return $defaultValue;
		}
	}
	
	/**
	 * Concatenate data
	 *
	 * @param mixed $source
	 * @return PHPLinq_ILinqProvider
	 */
	public function concat($source) {
		$data = array_merge($this->select(), $source);
		return linqfrom($this->_from)->in($data);
	}
	
	/**
	 * First
	 *
	 * @param  string	$expression	Expression which creates a resulting element
	 * @return mixed
	 */
	public function first($expression = null) {
		$linqCommand = clone $this;
		$result = $linqCommand->skip(0)->take(1)->select($expression);
		if (count($result) > 0) {
			return array_shift($result);
		}
		return null;
	}
	
	/**
	 * FirstOrDefault 
	 *
	 * @param  string	$expression	Expression which creates a resulting element
	 * @param  mixed	$defaultValue Default value to return if nothing is found
	 * @return mixed
	 */
	public function firstOrDefault ($expression = null, $defaultValue = null) {
		$returnValue = $this->first($expression);
		if (!is_null($returnValue)) {
			return $returnValue;
		} else {
			return $defaultValue;
		}
	}
	
	/**
	 * Last
	 *
	 * @param  string	$expression	Expression which creates a resulting element
	 * @return mixed
	 */
	public function last($expression = null) {
		$linqCommand = clone $this;
		$result = $linqCommand->reverse()->skip(0)->take(1)->select($expression);
		if (count($result) > 0) {
			return array_shift($result);
		}
		return null;
	}
	
	/**
	 * LastOrDefault 
	 *
	 * @param  string	$expression	Expression which creates a resulting element
	 * @param  mixed	$defaultValue Default value to return if nothing is found
	 * @return mixed
	 */
	public function lastOrDefault ($expression = null, $defaultValue = null) {
		$returnValue = $this->last($expression);
		if (!is_null($returnValue)) {
			return $returnValue;
		} else {
			return $defaultValue;
		}
	}
	
	/**
	 * Single
	 *
	 * @param  string	$expression	Expression which creates a resulting element
	 * @return mixed
	 */
	public function single($expression = null) {
		return $this->first($expression);
	}
	
	/**
	 * SingleOrDefault 
	 *
	 * @param  string	$expression	Expression which creates a resulting element
	 * @param  mixed	$defaultValue Default value to return if nothing is found
	 * @return mixed
	 */
	public function singleOrDefault ($expression = null, $defaultValue = null) {
		return $this->firstOrDefault($expression, $defaultValue);
	}
	
	/**
	 * Join
	 *
	 * @param string $name
	 * @return PHPLinq_Initiator
	 */
	public function join($name) {
		return new PHPLinq_Initiator($name, $this);
	}
	
	/**
	 * On
	 *
	 * @param  string	$expression	Expression representing join condition
	 * @return PHPLinq_ILinqProvider
	 */
	public function on($expression) {
		$this->_joinCondition = new PHPLinq_Expression($expression, $this->_from);
		return $this->_parentProvider;
	}
	
	/**
	 * Count elements
	 *
	 * @return int Element count
	 */
	public function count() {
		$linqCommand = clone $this;
		$result = $linqCommand->select('$x');
		return count($result);
	}
	
	/**
	 * Sum elements
	 *
	 * @return mixed Sum of elements
	 */
	public function sum() {
		$linqCommand = clone $this;
		$result = $linqCommand->select('$x');
		return array_sum($result); // $this->aggregate(0, '$s, $t => $s + $t');
	}
	
	/**
	 * Minimum of elements
	 *
	 * @return mixed Minimum of elements
	 */
	public function min(){
		$linqCommand = clone $this;
		$result = $linqCommand->select('$x');
		return min($result);
	}
	
	/**
	 * Maximum of elements
	 *
	 * @return mixed Maximum of elements
	 */
	public function max(){
		$linqCommand = clone $this;
		$result = $linqCommand->select('$x');
		return max($result);
	}
	
	/**
	 * Average of elements
	 *
	 * @return mixed Average of elements
	 */
	public function average(){
		$linqCommand = clone $this;
		$result = $linqCommand->select('$x');
		return sum($result) / count($result);
	}

	/**
	 * Aggregate
	 * 
	 * Example: Equivalent of count(): $this->aggregate(0, '$s, $t => $s + 1');
	 *
	 * @param int $seed	Seed
	 * @param string $expression	Expression defining the aggregate
	 * @return mixed aggregate
	 */
	public function aggregate($seed = 0, $expression) {
		$codeExpression = new PHPLinq_Expression($expression);
		
		$runningValue = $seed;
		foreach ($this->_data as $value) {
			$runningValue = $codeExpression->execute( array($runningValue, $value) );
		}
		
		return $runningValue;
	}
}
