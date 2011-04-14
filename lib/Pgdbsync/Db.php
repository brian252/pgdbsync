<?php

namespace Pgdbsync;

class Db
{

	private $masterDb = null;


	public function setMaster(DbConn $db)
	{
		$this->masterDb = $db;
	}


	private $slaveDb = array();


	public function setSlave(DbConn $db)
	{
		$this->slaveDb[] = $db;
	}


	private function _buildConf(DbConn $db, $schema)
	{
		$out = array();
		$schemaDb = $db->schema($schema);
		
		// functions
		foreach ((array) $schemaDb->getFunctions() as $function) {
			$out['functions'][$function->getName()]['definition'] = $function->getDefinition();
		}
		
		// Sequences
		foreach ((array) $schemaDb->getSequences() as $sequence) {
			$out['sequences'][$sequence->getName()]['owner'] = $sequence->getOwner();
			$out['sequences'][$sequence->getName()]['increment'] = $sequence->getIncrement();
			$out['sequences'][$sequence->getName()]['minvalue'] = $sequence->getMinValue();
			$out['sequences'][$sequence->getName()]['maxvalue'] = $sequence->getMaxValue();
			$out['sequences'][$sequence->getName()]['startvalue'] = $sequence->getStartValue();
			
			// Grants
			foreach ((array) $sequence->grants() as $grant) {
				$out['sequences'][$sequence->getName()]['grants'][$grant] = $grant;
			}
		}
		
		// tables
		foreach ((array) $schemaDb->getTables() as $table) {
			
			$out['tables'][$table->getName()]['owner'] = $table->getOwner();
			$out['tables'][$table->getName()]['tablespace'] = $table->getTablespace();
			// Columns
			foreach ((array) $table->columns() as $column) {
				$out['tables'][$table->getName()]['columns2'][] = $column->getName();
				$out['tables'][$table->getName()]['columns'][$column->getName()]['type'] = $column->getType();
				$out['tables'][$table->getName()]['columns'][$column->getName()]['precision'] = $column->getPrecision();
				$out['tables'][$table->getName()]['columns'][$column->getName()]['nullable'] = $column->getIsNullable();
			}
			// Constraints
			foreach ((array) $table->constraints() as $constraint) {
				$out['tables'][$table->getName()]['constraints'][$constraint->getName()]['type'] = $constraint->getType();
				$out['tables'][$table->getName()]['constraints'][$constraint->getName()]['src'] = $constraint->getConstraint();
				$out['tables'][$table->getName()]['constraints'][$constraint->getName()]['columns'] = $constraint->getColumns();
			}
			// Grants
			foreach ((array) $table->grants() as $grant) {
				$out['tables'][$table->getName()]['grants'][$grant] = $grant;
			}
		}
		
		// Views
		foreach ((array) $schemaDb->getViews() as $view) {
			$out['views'][$view->getName()]['owner'] = $view->getOwner();
			$out['views'][$view->getName()]['definition'] = $view->getDefinition();
			
			// Grants
			foreach ((array) $view->grants() as $grant) {
				$out['views'][$view->getName()]['grants'][$grant] = $grant;
			}
		}
		return $out;
	}


	private function _createTables($schema, $tables, $master, &$diff, &$summary)
	{
		if (count((array) $tables) > 0) {
			foreach ($tables as $table) {
				$tablespace = $master['tables'][$table]['tablespace'];
				$_columns = array();
				foreach ((array) $master['tables'][$table]['columns'] as $column => $columnConf) {
					$type = $columnConf['type'];
					$precision = $columnConf['precision'];
					$nullable = $columnConf['nullable'] ? null : ' NOT NULL';
					$_columns[] = "{$column} {$type}" . (($precision != '') ? "({$precision})" : null) . $nullable;
				}
				foreach ((array) $master['tables'][$table]['constraints'] as $constraint => $constraintInfo) {
					switch ($constraintInfo['type']) {
						case 'CHECK':
							$constraintSrc = $constraintInfo['src'];
							$_columns[] = "CONSTRAINT {$constraint} CHECK {$constraintSrc}";
							break;
						case 'PRIMARY KEY':
							$constraintSrc = $constraintInfo['src'];
							$__columns = array();
							foreach ($constraintInfo['columns'] as $c) {
								$__columns[] = $master['tables'][$table]['columns2'][$c];
							}
							$columns = implode(', ', $__columns);
							$_columns[] = "CONSTRAINT {$constraint} PRIMARY KEY ({$columns}) ";
							break;
					}
				}
				$owner = $master['tables'][$table]['owner'];
				$columns = implode(",\n ", $_columns);
				$buffer = "\nCREATE TABLE {$schema}.{$table}(\n {$columns}\n)";
				$buffer.= "\nTABLESPACE {$tablespace};";
				$buffer.= "\nALTER TABLE {$schema}.{$table} OWNER TO {$owner};";
				foreach ((array) $master['tables'][$table]['grants'] as $grant) {	
					$buffer.= "\nGRANT ALL ON TABLE {$schema}.{$table} TO {$grant};";
				}
				$diff[] = $buffer;
				$summary['tables']['create'][] = "{$schema}.{$table}";
			}
		}
	}


	private function _deleteTables($schema, $tables, $master, &$diff, &$summary)
	{
		if (count((array) $tables) > 0) {
			foreach ($tables as $table) {
				$diff[] = "\nDROP TABLE {$schema}.{$table};";
				$summary['tables']['drop'][] = "{$schema}.{$table}";
			}
		}
	}


	private function _deleteViews($schema, $views, $master, &$diff, &$summary)
	{
		if (count((array) $views) > 0) {
			foreach ($views as $view) {
				$diff[] = "drop view {$schema}.{$view};";
				$summary['views']['drop'][] = "{$schema}.{$view}";
			}
		}
	}


	private function _deleteSequences($schema, $sequences, $master, &$diff, &$summary)
	{
		if (count((array) $sequences) > 0) {
			foreach ($sequences as $sequence) {
				$diff[] = "drop sequence {$schema}.{$sequence};";
				$summary['sequence']['drop'][] = "{$schema}.{$sequence}";
			}
		}
	}


	private function _deleteFunctions($schema, $functions, $master, &$diff, &$summary)
	{
		if (count((array) $functions) > 0) {
			foreach ($functions as $function) {
				$diff[] = "drop function {$function};";
				$summary['function']['drop'][] = "{$function}";
			}
		}
	}


	private function _createFunctions($schema, $functions, $master, &$diff, &$summary)
	{
		if (count((array) $functions) > 0) {
			foreach ($functions as $function) {
				$buffer = $master['functions'][$function]['definition'];
				$summary['function']['create'][] = "{$function}";
				$diff[] = $buffer;
			}
		}
	}


	private function _createSequences($schema, $sequences, $master, &$diff, &$summary)
	{
		if (count((array) $sequences) > 0) {
			foreach ($sequences as $sequence) {
				$this->_createSequence($schema, $sequence, $master, $diff, $summary);
			}
		}
	}


	private function _createSequence($schema, $sequence, $master, &$diff, &$summary)
	{
		$increment = $master['sequences'][$sequence]['increment'];
		$minvalue = $master['sequences'][$sequence]['minvalue'];
		$maxvalue = $master['sequences'][$sequence]['maxvalue'];
		$start = $master['sequences'][$sequence]['startvalue'];
		
		$owner = $master['sequences'][$sequence]['owner'];
		$buffer = "\nCREATE SEQUENCE {$schema}.{$sequence}";
		$buffer.= "\n  INCREMENT {$increment}";
		$buffer.= "\n  MINVALUE {$minvalue}";
		$buffer.= "\n  MAXVALUE {$maxvalue}";
		$buffer.= "\n  START 1;";
		$buffer.= "\nALTER TABLE {$schema}.{$sequence} OWNER TO {$owner};";
		foreach ($master['sequences'][$sequence]['grants'] as $grant) {	
			$buffer.= "\nGRANT ALL ON TABLE {$schema}.{$sequence} TO {$grant};";
		}
		$diff[] = $buffer;
		$summary['secuence']['create'][] = "{$schema}.{$sequence}";
	}


	private function _createView($schema, $view, $master, &$diff, &$summary)
	{
		$definition = $master['views'][$view]['definition'];
		$owner = $master['views'][$view]['owner'];
		$buffer = "\nCREATE OR REPLACE VIEW {$schema}.{$view} AS \n";
		$buffer.= "  " . $definition . ";";
		$buffer.= "\nALTER TABLE {$schema}.{$view} OWNER TO {$owner};";
		foreach ((array) $master['views'][$view]['grants'] as $grant) {	
			$buffer.= "\nGRANT ALL ON TABLE {$schema}.{$view} TO {$grant};";
		}
		$diff[] = $buffer;
		$summary['view']['create'][] = "{$schema}.{$view}";
	}


	private function _createViews($schema, $views, $master, &$diff, &$summary)
	{
		if (count((array) $views) > 0) {
			foreach ($views as $view) {
				$this->_createView($schema, $view, $master, $diff, $summary);
			}
		}
	}


	private function _addColumns($table, $columns, $master, &$diff, &$summary)
	{
		if (count((array) $columns) > 0) {
			foreach ($columns as $column) {
				$diff[] = "\nadd column {$column} to table {$table}";
				$summary['column']['create'][] = "{$schema}.{$view}.{$column}";
			}
		}
	}


	private function _deleteColumns($table, $columns, $master, &$diff, &$summary)
	{
		if (count((array) $columns) > 0) {
			foreach ($columns as $column) {
				$diff[] = "delete column {$column} to table {$table}";
				$summary['column']['drop'][] = "{$schema}.{$table} {$column}";
			}
		}
	}


	private function _alterColumn($schema, $table, $column, $master, &$diff, &$summary)
	{
		$masterType = $master['tables'][$table]['columns'][$column]['type'];
		$masterPrecision = $master['tables'][$table]['columns'][$column]['precision'];
		$diff[] = "\nALTER TABLE {$schema}.{$table} ALTER {$column} TYPE {$masterType}({$masterPrecision});";
		$summary['column']['alter'][] = "{$schema}.{$table} {$column}";
	}


	public function summary($schema)
	{
		$buffer = array();
		$data = $this->_diff($schema);
		foreach ($data as $row) {
			if (count($row['summary']) > 0) {
				$title = "HOST : " . $row['db']->dbHost() . " :: " . $row['db']->dbName();
				$buffer[] = $title;
				$buffer[] = str_repeat("-", strlen($title));
			
				foreach ($row['summary'] as $type => $info) {
					$buffer[] = $type;
					foreach ($info as $mode => $objects) {
						foreach ($objects as $object) {
							$buffer[] = " " . $mode . " :: " . $object;
						}
					}
				}
				$buffer[] = "\n";
			}
		}
		return implode("\n", $buffer). "\n";
	}


	public function run($schema) 
	{
		$errors = array();
		$data = $this->_diff($schema);
		foreach ($data as $row) {
			$db = $row['db'];
			$host = $db->dbHost() . " :: " . $db->dbName();
			foreach ($row['diff'] as $item) {
				try {
					$db->exec($item);
				} catch (\PDOException $e) {
					$errors[$host][] = array($item, $e->getMessage());
				}
			}
		}
		return $errors;
	}


	public function raw($schema)
	{
		return $this->_diff($schema);
	}


	public function diff($schema)
	{
		$buffer = array();
		$data = $this->_diff($schema);
		foreach ($data as $row) {
			if (count($row['diff']) > 0) {
				$title = "HOST : " . $row['db']->dbHost() . " :: " . $row['db']->dbName();
				$buffer[] = $title;
				$buffer[] = str_repeat("-", strlen($title));

				foreach ($row['diff'] as $item) {
					$buffer[] = $item;
				}
				$buffer[] = "\n";
			} else {
				$buffer[] = "Already sync : " . $row['db']->dbHost() . " :: " . $row['db']->dbName();
			}
			
		}
		return implode("\n", $buffer) . "\n";
	}


	private function _diff($schema)
	{
		$out = array();
		$master = $this->_buildConf($this->masterDb->connect(), $schema);
		foreach ($this->slaveDb as $slaveDb) {
			$slave = $this->_buildConf($slaveDb->connect(), $schema);
			if (md5(serialize($master)) == md5(serialize($slave))) {
				//echo "[OK] <b>{$schema}</b> " . $slaveDb->dbName() . "<br/>";
				$out[] = array(
					'db' => $slaveDb,
					'diff' => array(),
					'summary' => array(),
					);
			} else {
				$diff = $summary = array();
				
				// FUNCTIONS
				$masterFunctions = isset($master['functions']) ? array_keys((array) $master['functions']) : array();
				$slaveFunctions = isset($slave['functions']) ? array_keys((array) $slave['functions']) : array();
				// delete deleted functions
				$deletedFunctions = array_diff($slaveFunctions, $masterFunctions);
				if (count($deletedFunctions) > 0) {
					$this->_deleteFunctions($schema, $deletedFunctions, $master, $diff, $summary);
				}
				// create new functions
				$newFunctions = array_diff($masterFunctions, $slaveFunctions);
				
				// check diferences
				foreach ($masterFunctions as $functionName) {
					if (!in_array($functionName, $newFunctions)) {
						$definitionMaster = $master['functions'][$functionName]['definition'];
						$definitionSlave = $slave['functions'][$functionName]['definition'];
					
						if (md5($definitionMaster) != md5($definitionSlave)) {
							$newFunctions[] = $functionName;
						}
					}
				}
				
				if (count($newFunctions) > 0) {
					$this->_createFunctions($schema, $newFunctions, $master, $diff, $summary);
				}
				
				// SEQUENCES
				$masterSequences = isset($master['sequences']) ? array_keys((array) $master['sequences']) : array();
				$slaveSequences = isset($slave['sequences']) ? array_keys((array) $slave['sequences']) : array();

				// delete deleted sequences
				$deletedSequences = array_diff($slaveSequences, $masterSequences);
				if (count($deletedSequences) > 0) {
					$this->_deleteSequences($deletedSequences, $master, $diff, $summary);
				}
				// create new sequences
				$newSequences = array_diff($masterSequences, $slaveSequences);
				if (count($newSequences) > 0) {
					$this->_createSequences($schema, $newSequences, $master, $diff, $summary);
				}
				
				// TABLES
				
				$masterTables = isset($master['tables']) ? array_keys((array) $master['tables']) : array();
				$slaveTables = isset($slave['tables']) ? array_keys((array) $slave['tables']) : array();
				
				// delete deleted tables
				$deletedTables = array_diff($slaveTables, $masterTables);
				if (count($deletedTables) > 0) {
					$this->_deleteTables($schema, $deletedTables, $master, $diff, $summary);
				}
				
				// create new tables
				$newTables = array_diff($masterTables, $slaveTables);
				if (count($newTables) > 0) {
					$this->_createTables($schema, $newTables, $master, $diff, $summary);
				}
	
				foreach ($masterTables as $table) {
					if (in_array($table, $newTables)) {
						continue;
					}
					// check new columns in $master and not in $slave
					// check deleted columns in $master (exits in $slave and not in master)
					$masterColumns = array_keys((array) $master['tables'][$table]['columns']);
					$slaveColumns = array_keys((array) $slave['tables'][$table]['columns']);
				
					$newColumns = array_diff($masterColumns, $slaveColumns);
					if (count($newColumns) > 0) {
						$this->_addColumns($table, $newColumns, $master, $diff, $summary);
					}
					
					$deletedColumns = array_diff($slaveColumns, $masterColumns);
					$this->_deleteColumns($table, $deletedColumns, $master, $diff, $summary);
		 
					foreach ($masterColumns as $column) {
						// check modifications (different between $master and $slave)
						// check differences in type
						$masterType = $master['tables'][$table]['columns'][$column]['type'];
						$slaveType = $slave['tables'][$table]['columns'][$column]['type'];
						// check differences in precission
						$masterPrecission = $master['tables'][$table]['columns'][$column]['precision'];
						$slavePrecission = $slave['tables'][$table]['columns'][$column]['precision'];
						
						if ($masterType != $slaveType || $masterPrecission != $slavePrecission) {
							$this->_alterColumn($schema, $table, $column, $master, $diff, $summary);
						}
					}
				}
				// VIEWS
				$masterViews = isset($master['views']) ? array_keys((array) $master['views']) : array();
				$slaveViews = isset($slave['views']) ? array_keys((array) $slave['views']) : array();
				
				// delete deleted views
				$deletedViews = array_diff($slaveViews, $masterViews);
				if (count($deletedViews) > 0) {
					$this->_deleteViews($schema, $deletedViews, $master, $diff, $summary);
				}
				
				// create new views
				$newViews = array_diff($masterViews, $slaveViews);
				if (count($newViews) > 0) {
					$this->_createViews($schema, $newViews, $master, $diff, $summary);
				}
				
				foreach ($masterViews as $view) {
					if (in_array($view, $newViews)) {
						continue;
					}
					
					if ($master['views'][$view]['definition'] !== $slave['views'][$view]['definition']) {
						$this->_createView($schema, $view, $master, $diff, $summary);
					}
				}
				$out[] = array(
					'db' => $slaveDb,
					'diff' => $diff,
					'summary' => $summary,
					);
			}
		}
		return $out;
	}
}

?>
