<?php

class Filters
{
	private static function addSubDomainToFilter(&$parameters, $excludeSubdomain)
	{
		global $subDomainKey, $subDomainRow;
		if (!isset($subDomainKey)) return false;

		Filters::addFilter($parameters, $subDomainKey, $subDomainRow, $excludeSubdomain);
		return true;
	}

	private static function addFilter(&$parameters, $key, $row, $excludeSubdomain)
	{
		global $twig;
		$twig->addGlobal("KillboardName", $row["name"]);
		if (!$excludeSubdomain) {
			if (!isset($parameters[$key])) $parameters[$key] = array();
			else if (!is_array($parameters[$key])) $parameters[$key] = array($parameters[$key]);
			$id = (int)$row[$key];
			$parameters[$key][] = $id;
			if (!isset($parameters["kills"]) && !isset($parameters["losses"])) $parameters["kills"] = true;
		}
		return true;
	}

	private static function grabParameters($parameters, $name)
	{
		$retValue = isset($parameters[$name]) ? $parameters[$name] : null;
		if ($retValue === null) return $retValue;
		if (!is_array($retValue)) $retValue = array($retValue);
		return $retValue;
	}

	private static function buildWhere(&$tables, &$whereClauses, $table, $column, $parameters)
	{
		$array = Filters::grabParameters($parameters, $column);
		if ($array === null || !is_array($array) || sizeof($array) == 0) return "";
		// Ensure SQL safe parameters
		$cleanArray = array();
		foreach ($array as $value) $cleanArray[] = "'" . (int)$value . "'";
		$tables[] = $table;
		$not = "";
		if (Util::startsWith($column, "!")) {
			$not = " not ";
			$column = substr($column, 1);
		}
		if ($column == "groupID") {
			//$whereClauses[] = "(p.$column $not in (" . implode(",", $cleanArray) . ") or p.vGroupID $not in (" . implode(",", $cleanArray) . "))";
			$whereClauses[] = "(p.vGroupID $not in (" . implode(",", $cleanArray) . "))";
		} else $whereClauses[] = "p.$column $not in (" . implode(",", $cleanArray) . ")";
	}

	public static function buildFilters(&$tables, &$combined, &$whereClauses, &$parameters, $allTime = true)
	{
		global $subDomainRow;
		$excludeSubdomain = @$parameters["excludeSubdomain"] == true;
		Filters::addSubDomainToFilter($parameters, $excludeSubdomain);

		// zz_participants filters
		$participants = "zz_participants p";
		$filterColumns = array("allianceID", "characterID", "corporationID", "factionID", "shipTypeID", "groupID", "solarSystemID", "regionID");
		foreach ($filterColumns as $filterColumn) {
			Filters::buildWhere($tables, $combined, $participants, $filterColumn, $parameters);
			Filters::buildWhere($tables, $combined, $participants, "!$filterColumn", $parameters);
		}

		if (array_key_exists("year", $parameters)) $year = (int)$parameters["year"]; // Optional
		if (array_key_exists("week", $parameters)) $week = (int)$parameters["week"]; // Optional
		if (array_key_exists("month", $parameters)) $month = (int)$parameters["month"]; // Optional
		if (!array_key_exists("pastSeconds", $parameters) && $allTime == false && (!isset($year) || !isset($week))) {
			$year = array_key_exists("year", $parameters) ? (int)$parameters["year"] : date("Y");
			$week = array_key_exists("week", $parameters) ? (int)$parameters["week"] : date("W");
		}

		if (array_key_exists("api-only", $parameters)) {
			$tables[] = "zz_participants p";
			$whereClauses[] = "p.killID > 0";
		}

		if (array_key_exists("solo", $parameters) && $parameters["solo"] === true) {
			$tables[] = "zz_participants p";
			$whereClauses[] = "p.number_involved = 1";
			$whereClauses[] = "p.vGroupID not in (237, 29, 31)";
		}

		if (array_key_exists("relatedTime", $parameters)) {
			$relatedTime = $parameters["relatedTime"];
			$unixTime = strtotime($relatedTime);
			if ($unixTime % 3600 != 0) throw new Exception("User attempted an unsupported value.  Fail.");
			$tables[] = "zz_participants p";
			$whereClauses[] = "p.unix_timestamp >= " . ($unixTime - 3600);
			$whereClauses[] = "p.unix_timestamp <= " . ($unixTime + 3600);
			$parameters["limit"] = 10000;
		}
		if (array_key_exists("startTime", $parameters)) {
			$time = $parameters["startTime"];
			$unixTime = strtotime($time);
			$tables[] = "zz_participants p";
			$whereClauses[] = "p.unix_timestamp >= " . ((int)$unixTime);
		}
		if (array_key_exists("endTime", $parameters)) {
			$time = $parameters["endTime"];
			$unixTime = strtotime($time);
			$tables[] = "zz_participants p";
			$whereClauses[] = "p.unix_timestamp <= " . ((int)$unixTime);
		}

		if (array_key_exists("pastSeconds", $parameters)) {
			$tables[] = "zz_participants p";
			$whereClauses[] = "p.unix_timestamp >= (unix_timestamp() - " . ((int)$parameters["pastSeconds"]) . ")";
		}

		if (array_key_exists("iskValue", $parameters)) {
			$tables[] = "zz_participants p";
			$whereClauses[] = "p.total_price >= '" . ((int)$parameters["iskValue"]) . "'";
		}

		if (array_key_exists("w-space", $parameters)) {
			$tables[] = "zz_participants p";
			$whereClauses[] = "(regionID >= '11000001' and regionID <= '11000030')";
		}

		if (array_key_exists("beforeKillID", $parameters)) {
			$killID = (int)$parameters["beforeKillID"];
			$tables[] = "zz_participants p";
			$whereClauses[] = "killID < $killID";
		}
		if (array_key_exists("afterKillID", $parameters)) {
			$killID = (int)$parameters["afterKillID"];
			$tables[] = "zz_participants p";
			$whereClauses[] = "killID > $killID";
		}

		$kills = array_key_exists("kills", $parameters);
		$losses = array_key_exists("losses", $parameters); //|| (array_key_exists("solo", $parameters));
		if ((array_key_exists("mixed", $parameters) && $parameters["mixed"] == true) || array_key_exists("iskValue", $parameters)) {
		}
		else if ($losses) {
			$tables[] = $participants;
			$whereClauses[] = "p.isVictim = '1'";
		}
		else if ($kills) { //|| sizeof($whereClauses)) { /* Removed this part, because when the whereClauses was set (which it is, if you add any sort of parameter) it'd default to only kills. Which was not expected behaviour.*/
			$tables[] = $participants;
			$whereClauses[] = "p.isVictim = '0'";
		}

		$tables = array_unique($tables);
		if (sizeof($tables) == 0) $tables[] = "zz_participants p";
		foreach ($tables as $table) {
			$tablePrefix = substr($table, strlen($table) - 1, 1);
			if (isset($year)) $whereClauses[] = "${tablePrefix}.year = $year";
			if (isset($week)) $whereClauses[] = "${tablePrefix}.week = $week";
			if (isset($month)) $whereClauses[] = "${tablePrefix}.month = $month";
		}
	}
}