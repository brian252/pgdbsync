<?php

namespace Pgdbsync;

class DbConn
{

	private $conf = array();
	function __construct($conf)
	{
		$this->conf = $conf;
	}


	private $pdo = null;
	public function connect()
	{
		$dsn = $this->getDsn();

		$this->pdo = new \PDO($dsn, $this->conf['USER'], $this->conf['PASSWORD']);
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->dsn = $dsn;
		return $this;
	}


	public function dbName()
	{
		return $this->conf['DBNAME'];
	}


	public function dbHost()
	{
		return $this->conf['HOST'];
	}


	public function getDsn()
	{
		$dsnOptionsArr = array();
		if (isset($this->conf['HOST'])) {
			$dsnOptionsArr[] = "host={$this->conf['HOST']}";
		}
		if (isset($this->conf['DBNAME'])) {
			$dsnOptionsArr[] = "dbname={$this->conf['DBNAME']}";
		}
		if (isset($this->conf['CHARTSET'])) {
			$dsnOptionsArr[] = "charset={$this->conf['CHARTSET']}";
		}
		if (isset($this->conf['PORT'])) {
			$dsnOptionsArr[] = "port={$this->conf['PORT']}";
		}
		return "{$this->conf['TYPE']}:".implode(';', $dsnOptionsArr);
	}


	private $_schema = null;
	public function schema($schema)
	{
		$this->_schema = $schema;
		return $this;
	}


	const SQL_GET_VIEWS = "
		SELECT *
		FROM pg_views
		WHERE
			schemaname = :SCHEMA
	";


	function getViews()
	{
		$stmt = $this->pdo->prepare(self::SQL_GET_VIEWS);
		$stmt->execute(array(
			'SCHEMA' => $this->_schema,
			));	
		foreach ($stmt->fetchAll() as $row) {
			$out[] = new View($this->pdo, $row, $this->_schema);
		}
		return $out;
	}


	const SQL_GET_SEQUENCES = "
		SELECT C.relname, R.rolname \"owner\", relacl
		FROM pg_class C, pg_catalog.pg_roles R
		WHERE C.relkind = 'S'
			AND C.relowner = R.oid
			AND C.relnamespace IN (
				SELECT oid
				FROM pg_namespace
		 		WHERE nspname = :SCHEMA
			);
	";


	function getSequences()
	{
		$out = array();
		$stmt = $this->pdo->prepare(self::SQL_GET_SEQUENCES);
		$stmt->execute(array(
			'SCHEMA' => $this->_schema,
			));	
		foreach ($stmt->fetchAll() as $row) {
			$out[] = new Sequence($this->pdo, $row, $this->_schema);
		}
		return $out;
	}


	const SQL_GET_FUNCTIONS = "
		SELECT
			A.OID, B.NSPNAME, A.PRONAME, T.LANNAME,
			(SELECT T.TYPNAME FROM PG_TYPE T WHERE T.OID=A.PRORETTYPE) AS TIPORETORNO,
			pronargs,
			pronargdefaults,
			proargtypes,
			proargmodes,
			proargnames,
			proargdefaults,
			proallargtypes
		FROM
			PG_PROC A,
			PG_NAMESPACE B,
			PG_LANGUAGE T
		WHERE
			A.PRONAMESPACE=B.OID AND
			A.PROLANG=T.OID AND
			B.NSPNAME = :SCHEMA
		ORDER BY
			B.NSPNAME, A.PRONAME
	";


	function exec($sql)
	{
		return $this->pdo->exec($sql);	
	}


	function getFunctions()
	{
		$out = array();
		$stmt = $this->pdo->prepare(self::SQL_GET_FUNCTIONS);
		$stmt->execute(array(
			'SCHEMA' => $this->_schema,
			));	
		foreach ($stmt->fetchAll() as $row) {
			$out[] = new Functiondb($this->pdo, $row, $this->_schema);
		}
		return $out;
	}


	public function getTables()
	{
		$sql = "select * from pg_catalog.pg_tables where schemaname=:SCHEMA order by schemaname, tablename";
		if (is_null($this->_schema)) {
			throw new Exception("Schema must set");
		}
		$out = array();
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array('SCHEMA' => strtolower($this->_schema)));
		while ($row = $stmt->fetch()) {
			$out[] = new Table($this->pdo, $row['schemaname'], $row['tablename'], $row['tableowner'], $row['tablespace'], $row['hasindexes'], $this->dsn);
		}
		$stmt = null;
		return $out;
	}

}

?>
