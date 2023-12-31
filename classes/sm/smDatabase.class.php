<?php

/*
 * PHP Server Monitor v2.0.1
 * Monitor your servers with error notification
 * http://phpservermon.sourceforge.net/
 *
 * Copyright (c) 2008-2011 Pepijn Over (ipdope@users.sourceforge.net)
 *
 * This file is part of PHP Server Monitor.
 * PHP Server Monitor is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP Server Monitor is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP Server Monitor.  If not, see <http://www.gnu.org/licenses/>.
 */

class smDatabase {

	protected $debug = array();
	protected $last_inserted_id;
	protected $link;
	protected $num_rows_found;
	protected $num_rows_returned;

	function __construct() {
		// Initialize connection
		$this->link = mysqli_connect(SM_DB_HOST, SM_DB_USER, SM_DB_PASS, SM_DB_NAME);
		
		if (!$this->link) {
			trigger_error("Connection error: " . mysqli_connect_errno() . ": " . mysqli_connect_error());
			exit; // Stop script execution here
		}
		
		if (!mysqli_select_db($this->link, SM_DB_NAME)) {
			trigger_error(mysqli_errno($this->link) . ": " . mysqli_error($this->link));
			exit; // Stop script execution here
		}
		
		// Setting the utf collection
		mysqli_query($this->getLink(), "SET NAMES utf8;");
		mysqli_query($this->getLink(), "SET CHARACTER SET 'utf8';");
	}

	/**
	 * Executes a query
	 *
	 * @param $sql string MySQL query
	 * @return resource mysql resource
	 */

	 public function executeQuery($sql) {
		echo "Ejecutando query";
		echo $sql;
		$result = mysqli_query($this->getLink(), $sql);
	
		if (!$result) {
			trigger_error(mysqli_errno($this->getLink()) . ': ' . mysqli_error($this->getLink()));
			return false;
		}
	
		if (is_object($result) && mysqli_num_rows($result) > 0) {
			// Rows returned
			$this->num_rows_returned = mysqli_num_rows($result);
	
			// Rows found
			$result_num_rows_found = $this->fetchResults(mysqli_query($this->getLink(), 'SELECT FOUND_ROWS();'));
			$this->num_rows_found = $result_num_rows_found[0]['FOUND_ROWS()'];
		}
	
		if (substr(strtolower(trim($sql)), 0, 6) == 'insert') {
			// we have an insert
			$this->last_inserted_id = mysqli_insert_id($this->getLink());
			$result = $this->last_inserted_id;
		}
	
		return $result;
	}	

	/**
	 * Exectues query and fetches result
	 *
	 * @param $query string MySQL query
	 * @return $result array
	 */
	public function query($query) {

		// Execute query and process results
		$result_resource = $this->executeQuery($query);
		$result = $this->fetchResults($result_resource);

		return $result;
	}

	/**
	 * Fetch results from a query
	 *
	 * @param resource $result result from a mysql query
	 * @return array $array with results (multi-dimimensial) for more than one rows
	 */

	public function fetchResults($result_query){

		if (!is_resource($result_query)) {
			return array();
		}

		$num_rows = mysqli_num_rows($result_query);

		$result = array();
		while($record = mysqli_fetch_assoc($result_query)) {
			$result[] = $record;
		}

		return $result;
	}

	/**
	 * Performs a select on the given table and returns an multi dimensional associative array with results
	 *
	 * @param string $table tablename
	 * @param mixed $where string or array with where data
	 * @param array $fields array with fields to be retrieved. if empty all fields will be retrieved
	 * @param string $limit limit. for example: 0,30
	 * @param array $orderby fields for the orderby clause
	 * @param string $direction ASC or DESC. Defaults to ASC
	 * @return array multi dimensional array with results
	 */

	public function select($table, $where = null, $fields = null, $limit = '', $orderby = null, $direction = 'ASC'){
		// build query
		$query_parts = array();
		$query_parts[] = 'SELECT SQL_CALC_FOUND_ROWS';

		// Fields
		if ($fields !== null && !empty($fields)) {
			$query_parts[] = "`".implode('`,`', $fields)."`";
		} else {
			$query_parts[] = ' * ';
		}

		// From
		$query_parts[] = "FROM `{$table}`";

		// Where clause
		$query_parts[] = $this->buildWhereClause($table, $where);

		// Order by
		if ($orderby !== null && !empty($orderby)) {
			$orderby_clause = 'ORDER BY ';

			foreach($orderby as $field) {
				$orderby_clause .= "`{$field}`, ";
			}
			$query_parts[] = substr($orderby_clause, 0, -2) . ' ' . $direction;
		}

		// Limit
		if ($limit != '') {
			$query_parts[] = 'LIMIT ' . $limit;
		}

		$query = implode(' ', $query_parts);

		// Get results
		$result = $this->query($query);

		return $result;
	}

	public function selectRow($table, $where = null, $fields = null, $limit = '', $orderby = null, $direction = 'ASC') {
		$result = $this->select($table, $where, $fields, $limit, $orderby, $direction);

		if ($this->getNumRowsReturned() == '1') {
			$result = $result[0];
		}
		return $result;
	}

	/**
	 * Remove a record from database
	 *
	 * @param string $table tablename
	 * @param mixed $where Where clause array or primary Id (string) or where clause (string)
	 * @return boolean
	 */
	public function delete($table, $where = null){

		if ($table != '') {

			$sql = 'DELETE FROM `'.$table.'` ' . $this->buildWhereClause($table, $where);

			$this->query($sql);
		}
	}

	/**
	 * Insert or update data to the database
	 *
	 * @param array $table table name
	 * @param array $data data to save or insert
	 * @param mixed $where either string ('user_id=2' or just '2' (works only with primary field)) or array with where clause (only when updating)
	 */
	public function save($table, $data, $where = null) {

		if ($where === null) {
			// insert mode
			$query = "INSERT INTO ";
		} else {
			$query = "UPDATE ";
		}

		$query .= "`{$table}` SET ";

		foreach($data as $field => $value) {
			$value = $this->escapeValue($value);
			$query .= "`{$table}`.`{$field}`='{$value}', ";
		}

		$query = substr($query, 0, -2) . ' ' . $this->buildWhereClause($table, $where);

		return $this->query($query);
	}

	/**
	 * Build WHERE clause for query
	 *
	 * @param string $table table name
	 * @param mixed $where can be primary id (eg '2'), can be string (eg 'name=pepe') or can be array
	 * @return string sql where clause
	 */
	public function buildWhereClause($table, $where = null) {

		$query = '';

                if ($where !== null) {
                        if (is_array($where)) {
                                $query .= " WHERE ";

                                foreach($where as $field => $value) {
                                	$value = $this->escapeValue($value);
									$query .= "`{$table}`.`{$field}`='{$value}' AND ";
                                }
                                $query = substr($query, 0, -5);
                        } else {
                            if (strpos($where, '=') === false) {
                            	// no field given, use primary field
                                $structure = $this->getTableStructure($table);
                                $where = $this->escapeValue($where);
                                $query .= " WHERE `{$table}`.`{$structure['primary']}`='{$where}'";
                            } elseif (strpos(strtolower(trim($where)), 'where') === false) {
                            	$query .= " WHERE {$where}";
                            } else {
                            	$query .= ' '.$where;
                            }
                        }
                }
		return $query;
	}

	/**
	 * Get table structure and primary key
	 *
	 * @param string $table table name
	 * @return array primary key and database structure
	 */
	public function getTableStructure($table) {
		if ($table == '') return false;

		$structure = $this->query("DESCRIBE `{$table}`");

		if (empty($structure)) return false;

		// use arrray search function to get primary key
		$search_needle = array(
		        'key' => 'Key',
		        'value' => 'PRI'
		);
		$primary = pep_array_search_key_value(
		        $structure,
		        array(
		                'key' => 'Key',
		                'value' => 'PRI'
		        )
		);

		$primary_field = $structure[$primary[0]['path'][0]]['Field'];
		return array(
		        'primary' => $primary_field,
		        'fields' => $structure
		);
	}

	/**
	 * Get information about a field from the database
	 *
	 * @param string $table
	 * @param string $field
	 * @return array mysql field information
	 */
	public function getFieldInfo($table, $field) {
		if ($table == '' || $field == '') return array();

		$db_structure = $this->getTableStructure($table);

		$field_info = pep_array_search_key_value(
			$db_structure,
			array(
				'key' => 'Field',
				'value' => $field
			)
		);

		if (empty($field_info)) {
			return array();
		}

		// return field info
		return $field_info[0]['result'];
	}

	/**
	 * Formats the value for the SQL query to secure against injections
	 *
	 * @param string $value
	 * @return string
	 */
	public function escapeValue($value) {
		if(get_magic_quotes_gpc()) {
			$value = stripslashes($value);
		}
		$value = mysql_real_escape_string($value, $this->link);

		return $value;
	}

    /**
     * Get number of rows found
     *
     * @return int number of rows found
     */
    public function getNumRowsFound() {
            return $this->num_rows_found;
    }

    /**
     * Get number of rows returned
     *
     * @return int number of rows returned
     */
    public function getNumRowsReturned() {
            return $this->num_rows_returned;
    }

	/**
	* Get the database connection identifier
	*
	* @return object db connection
	*/
	public function getLink() {
		return $this->link;
	}
}

?>