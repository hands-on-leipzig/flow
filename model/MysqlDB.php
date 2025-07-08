<?php

namespace model;

use Dotenv\Dotenv;
use mysqli;
use mysqli_result;

require_once '../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 1), "conf.env");
$dotenv->load();

class MysqlDB
{

    public mysqli $mysqli;
    public \mysqli_stmt $stmt;
    public $mysqli_result;
    public $query;
    public $error;
    public $num_rows;

    protected $databaseName;
    protected $hostName;
    protected $userName;
    protected $passCode;

    function __construct($db = "kiosk")
    {
        $this->query = "";
        $this->mysqli_result = mysqli_result::class;

        $this->databaseName = $_ENV["DB_NAME_$db"];
        $this->hostName = $_ENV["DB_HOST_$db"];
        $this->userName = $_ENV["DB_USER_$db"];
        $this->passCode = $_ENV["DB_PW_$db"];
    }

    function dbConnect()
    {
        $this->mysqli = mysqli_connect($this->hostName, $this->userName, $this->passCode);
        mysqli_select_db($this->mysqli, $this->databaseName);
        mysqli_set_charset($this->mysqli, "utf8mb4");
        $this->stmt = mysqli_stmt_init($this->mysqli);
        return $this->mysqli;
    }

    function dbDisconnect(): void
    {
        mysqli_close($this->mysqli);
        unset($this->mysqli);
        unset($this->query);
        unset($this->mysqli_result);
        unset($this->databaseName);
        unset($this->hostName);
        unset($this->userName);
        unset($this->passCode);
    }

    public function now()
    {
        return (new DateTime)->format("Y-m-d H:i:s");
    }

    function runQuery()
    {
        $this->mysqli_result = mysqli_query($this->mysqli, $this->query);
        $this->error = mysqli_error($this->mysqli);
        if (is_object($this->mysqli_result)) {
            $this->num_rows = mysqli_num_rows($this->mysqli_result);
        } else {
            $this->num_rows = 0;
        }
    }

    /**
     * @param $tableName
     * @return mysqli_result
     */
    function selectAll($tableName): mysqli_result
    {
        $this->query = 'SELECT * FROM ' . $this->databaseName . '.' . $tableName;
        $this->runQuery();
        return $this->mysqli_result;
    }

    /**
     * @param $tableName
     * @param $column
     * @param $operator
     * @param $value
     * @param $valueType string
     * @param $sort string
     * @return mysqli_result
     */
    function selectWhere($tableName, $column, $operator, $value, string $valueType = "int", string $sort = ""): mysqli_result
    {
        $this->query = 'SELECT * FROM ' . $tableName . ' WHERE 1 = 1';

        if (!is_array($column) && !is_array($operator) && !is_array($value)) {
            $this->query .= ' AND ' . $column . ' ' . $operator . ' ';
            if ($valueType == 'int') {
                $this->query .= $this->escape($value);
            } else if ($valueType == 'char') {
                $this->query .= "'" . $this->escape($value) . "'";
            }
        } elseif (is_array($column) && is_array($operator) && is_array($value)) {
            foreach ($column as $i => $col) {
                $this->query .= " AND " . $col . " " . $operator[$i] . " ";
                if (is_string($value[$i]) && $value[$i] != "NULL") $this->query .= "'";
                $this->query .= $this->escape($value[$i]);
                if (is_string($value[$i]) && $value[$i] != "NULL") $this->query .= "'";
            }
        }
        if ($sort != "") $this->query .= " " . $sort;
        $this->runQuery();
        return $this->mysqli_result;
    }

    /**
     * @param $tableName
     * @param $column
     * @param $operator
     * @param $value
     * @param $valueType string
     * @param $sort string
     * @return array
     */

    function selectAsObj($tableName, $column, $operator, $value, string $valueType = "int", string $sort = "", $add_condition = ""): array
    {
        $result = array();
        $this->selectWhere($tableName, $column, $operator, $value, $valueType, $sort, $add_condition = "");
        for ($i = 0; $i < $this->mysqli_result->num_rows; $i++) {
            $result[] = mysqli_fetch_object($this->mysqli_result);
        }
        return $result;
    }

    public function select($q) {
        $this->execute($q);
        for ($i = 0; $i < $this->mysqli_result->num_rows; $i++) {
            $result[] = mysqli_fetch_object($this->mysqli_result);
        }
        return $result;
    }
    /**
     * Insert values into DB
     *
     * @param $tableName string Name of table to insert to
     * @param $columns   array  What columns to insert
     * @param $values    array  What values to insert
     *
     * @return string
     */
    function insertInto(string $tableName, array $columns, array $values): int
    {
        $this->query = 'INSERT INTO ' . $tableName . ' (';
        foreach ($columns as $column) {
            $this->query .= $column . ", ";
        }

        $this->query = substr($this->query, 0, -2);
        if (is_array($values[0])) {
            $this->query .= ') VALUES ';
            foreach ($values as $valueset) {
                $this->query .= "(";
                foreach ($valueset as $value) {
                    if (is_string($value) && $value != "NULL") $this->query .= "'";
                    $this->query .= $this->escape($value);
                    if (is_string($value) && $value != "NULL") $this->query .= "'";
                    $this->query .= ', ';
                }
                $this->query = substr($this->query, 0, -2);
                $this->query .= "), ";
            }
            $this->query = substr($this->query, 0, -2);
        } else {
            $this->query .= ') VALUES (';
            foreach ($values as $value) {
                if (is_string($value) && $value != "NULL") $this->query .= "'";
                $this->query .= $this->escape($value);
                if (is_string($value) && $value != "NULL") $this->query .= "'";
                $this->query .= ', ';
            }
            $this->query = substr($this->query, 0, -2);
            $this->query .= ')';
        }

        $this->runQuery();
        return mysqli_insert_id($this->mysqli);
    }

    /**
     * @param $tableName
     * @param $where
     * @param $columns
     * @param $values
     * @return int|string
     */

    function updateOld($tableName, $where, $columns, $values)
    {
        $this->query = 'UPDATE ' . $tableName . ' SET ';
        foreach ($values as $index => $value) {
            $this->query .= $columns[$index] . "=";
            if ($value["type"] == "char") {
                $this->query .= "'";
                $this->query .= $value["val"];
                $this->query .= "'";
            } else if ($value["type"] == 'int') {
                $this->query .= $value["val"];
            }
            $this->query .= ', ';
        }
        $this->query = substr($this->query, 0, -2);
        $this->query .= ' WHERE ' . $where;
        if (mysqli_query($this->mysqli, $this->query)) {
            return mysqli_insert_id($this->mysqli);
        }
        return -1;
    }

    /**
     * @param $tableName
     * @param $whereid
     * @param $columns
     * @param $values
     *
     * Either have $values = ["val1", "val2", val3] or $values = [["val1", "val2", val3],["val4", "val5", val6]]
     *
     *
     *
     * UPDATE config t1 JOIN config t2
     * ON t1.config_name = 'name1' AND t2.config_name = 'name2'
     * SET t1.config_value = 'value',
     * t2.config_value = 'value2';
     *
     * @param string|array $wherecolumn
     * @return int|string
     */
    function update($tableName, $whereid, $columns, $values, $wherecolumn = "id")
    {
        $this->query = 'UPDATE';
        if (!is_array($values[0])) {
            $this->query .= ' ' . $tableName . ' SET ';
            foreach ($columns as $index => $column) {
                $this->query .= $column . "=";
                if (is_string($values[$index]) && $values[$index] != "NULL") $this->query .= "'";
                $this->query .= $this->escape($values[$index]);
                if (is_string($values[$index]) && $values[$index] != "NULL") $this->query .= "'";
                $this->query .= ', ';
            }
            $this->query = substr($this->query, 0, -2);
            $this->query .= ' WHERE ';
            if (is_array($whereid)) {
                foreach ($whereid as $i => $wid) {
                    $this->query .= $wherecolumn[$i] . ' = ';
                    if (is_string($wid) && $wid != "NULL") $this->query .= "'";
                    $this->query .= $this->escape($wid);
                    if (is_string($wid) && $wid != "NULL") $this->query .= "'";
                    $this->query .= " AND ";
                }
                $this->query = substr($this->query, 0, -5);
            } else {
                $this->query .= $wherecolumn . ' = ';
                if (is_string($whereid) && $whereid != "NULL") $this->query .= "'";
                $this->query .= $this->escape($whereid);
                if (is_string($whereid) && $whereid != "NULL") $this->query .= "'";
            }
        } else {
            // UPDATE config t1 JOIN config t2 ...
            foreach ($values as $i => $v) {
                $this->query .= " " . $tableName . " t" . $i . " JOIN";
            }
            $this->query = substr($this->query, 0, -5);

            $this->query .= " ON";
            if (is_array($whereid[0])) {
                foreach ($values as $i => $v) {
                    foreach ($whereid[$i] as $cid => $wid) {
                        $this->query .= " t" . $i . "." . $wherecolumn[$cid] . " = ";
                        if (is_string($wid)) $this->query .= "'";
                        $this->query .= $this->escape($wid);
                        if (is_string($wid)) $this->query .= "'";
                        $this->query .= " AND";
                    }
                }
            } else {
                foreach ($values as $i => $v) {
                    $this->query .= " t" . $i . "." . $wherecolumn . " = ";
                    if (is_string($whereid[$i])) $this->query .= "'";
                    $this->query .= $this->escape($whereid[$i]);
                    if (is_string($whereid[$i])) $this->query .= "'";
                    $this->query .= " AND";
                }
            }
            $this->query = substr($this->query, 0, -4);

            $this->query .= ' SET';
            foreach ($values as $i => $v) {
                foreach ($columns as $c => $column) {
                    $this->query .= " t" . $i . "." . $column . " = ";
                    if (is_string($v[$c])) $this->query .= "'";
                    $this->query .= $this->escape($v[$c]);
                    if (is_string($v[$c])) $this->query .= "'";
                    $this->query .= ', ';
                }
            }
            $this->query = substr($this->query, 0, -2);
        }

        if (mysqli_query($this->mysqli, $this->query)) {
            return 1;
        }
        return -1;
    }

    /**
     * @param $table
     * @param $wherecolumn
     * @param $operator
     * @param $wherevalue
     * @return string
     */
    function delete($table, $wherecolumn, $operator, $wherevalue)
    {
        $this->query = sprintf("DELETE FROM %s WHERE", $table);
        if (is_array($wherecolumn) && is_array($operator) && is_array($wherevalue)) {
            foreach ($wherecolumn as $i => $column) {
                if (is_string($wherevalue[$i]) && $wherevalue[$i] != "NULL") $wherevalue[$i] = sprintf("'%s'", $wherevalue[$i]);
                $this->query .= sprintf(" %s %s %s AND", $column, $operator[$i], $wherevalue[$i]);
            }
            $this->query = substr($this->query, 0, -4);
        } else {
            $this->query .= sprintf(" %s %s %s", $wherecolumn, $operator, $wherevalue);
        }
        $this->runQuery();
        return $this->mysqli_result;
    }

    /**
     * @param $query
     * @return bool|mysqli_result
     */
    function execute($query)
    {
        $this->query = $query;
        $this->runQuery();
        return $this->mysqli_result;
    }

    function escape($string)
    {
        return mysqli_real_escape_string($this->mysqli, $string);
    }
}
