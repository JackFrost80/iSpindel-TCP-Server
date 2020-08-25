<?php

/* 
Visualizer for iSpindle using genericTCP with mySQL
Shows mySQL iSpindle data on the browser as a graph via Highcharts:
http://www.highcharts.com

Data access via mySQL for the charts is defined in here.

For the original project itself, see: https://github.com/universam1/iSpindel

Got rid of deprecated stuff, ready for Debian Stretch now.

Tozzi (stephan@sschreiber.de), Nov 25 2017

Apr 28 2019:
Intruduced selects for handling of strings from database. This allows usage of multiple languages based on DB configuration
Some changes required in the selects as UTF8mb4 encoding is required. Some tables parameters in the DB need to be changed to utf8mb4
Implemented function for spindle calibration. Spindles can now be calibrated via web interface.
Settings are now stored in the database and not in iSpindle.py. Some functions were added to change settings from web interface.
Select for new diagram introduced: apparent attenuation and ABV calculation with time trend possible.
Select for initial/original gravity added. Average of first two hours after reset for spindle are used to calculate OG
Added select to calculate delta plato for a defined timeframe and display in a trendchart.

Oct 14 2018:
Added Moving Average Selects, thanks to nice job by mrhyde
Minor fixes

Nov 04 2018:
Update of SQL queries for moving average calculations as usage of multiples spindles at the same time caused an issue and resulted in a mixed value of both spindle data 

Nov 16 2018
Function getcurrentrecipe rewritten: Recipe Name will be only returned if reset= true or timeframe < timeframe of last reset
Return of variables changed for all functions that return x/y diagram data. Recipe name is added in array and returned to php script

Jan 24 2019
- Function added to update TCP Server settings in Database
- Added ability to read field description from sql database for diefferent languages. can be easily expanded for more languages
- Function to write calibration data back to databses added which is used by calibration.php. Usercan send calibration data through frontend and does not need to open phpadmin

 */

// get despription fields from strings table in database.
// Language setting from settings database is used to return field in corresponding language
// e.e. Language = DE --> Description_DE column is selected
// can be extended w/o change of php code. to add for instance french, add column Description_FR to settings and strings tables.
// Add desriptions and set LANGUAGE in settings Database to FR
// File is the file which is calling the function (has to be also used in the strings table)
// field is the field for hich the description will be returned 

    if ((include_once './config/common_db_config.php') == FALSE){
       include_once("./config/common_db_default.php");
    }
       include_once("./config/tables.php");

function write_log($data)
{
    try {
        $log_to_console = 0;
    }
    catch (Exception $e) {
        $log_to_console = 0;
    } 
    if ($log_to_console == 1 ) {
        echo '<script>';
        echo 'console.log('. json_encode( $data ) .')';
        echo '</script>'; 
    }
}

function cleanData(&$str)
  {
    if($str == 't') $str = 'TRUE';
    if($str == 'f') $str = 'FALSE';
    if(strstr($str, '"')) $str = '"' . str_replace('"', '""', $str) . '"';
  }

function upgrade_data_table($conn)
{
    $max_time = ini_get("max_execution_time");
    // set max php execution time to 1 hr for this task
    set_time_limit (3600);
    // Create Archive Table
    $create_recipe_table = "CREATE TABLE `Archive` ( `Recipe_ID` INT NOT NULL AUTO_INCREMENT , 
                                         `Name` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL , 
                                         `ID` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL, 
                                         `Recipe` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL, 
                                         `Start_date` DATETIME NOT NULL , 
                                         `End_date` DATETIME NULL DEFAULT NULL, 
                                         `const1` DOUBLE NULL DEFAULT NULL, 
                                         `const2` DOUBLE NULL DEFAULT NULL, 
                                         `const3` DOUBLE NULL DEFAULT NULL, 
                                         PRIMARY KEY (`Recipe_ID`)) ENGINE = InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;";
    $result = mysqli_query($conn, $create_recipe_table) or die(mysqli_error($conn));

    // Add Recipe_ID and Comment columns to Data table
    $q_sql="ALTER TABLE `Data` ADD COLUMN `Recipe_ID` INT NOT NULL AFTER `Recipe`";
    $result = mysqli_query($conn, $q_sql) or die(mysqli_error($conn));
    $q_sql="ALTER TABLE `Data` ADD `Internal` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `Recipe_ID`";
    $result = mysqli_query($conn, $q_sql) or die(mysqli_error($conn));
    $q_sql="ALTER TABLE `Data` ADD `Comment` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `Internal`";
    $result = mysqli_query($conn, $q_sql) or die(mysqli_error($conn));


    // Select all entries with resetflag and sort them with ascending data to write nitial recipe_ID
    $q_sql="Select * FROM Data WHERE ResetFlag = '1' ORDER BY Timestamp ASC";
    $result = mysqli_query($conn, $q_sql) or die(mysqli_error($conn));
    $rows = mysqli_num_rows($result);
    if($rows > 0) {
        $recipe_id = 1;
        while ($row = mysqli_fetch_array($result)){
        $timestamp = $row[0];
        $name = $row[1];
        $ID = $row[2];
        $recipe = $row[11];
        
        $update_sql = "UPDATE Data SET Recipe_ID = '".$recipe_id."' WHERE Timestamp = '".$timestamp."' AND Name = '".$name."';";
        $update = mysqli_query($conn, $update_sql) or die(mysqli_error($conn));

        $const1=NULL;
        $const2=NULL;
        $const3=NULL;

        $valCalib = getSpindleCalibration($conn, $name );

        if ($valCalib[0])
        {
            $const1=$valCalib[1];
            $const2=$valCalib[2];
            $const3=$valCalib[3];
        }
        
        $entry_recipe_table_sql = "INSERT INTO `Archive` 
                                 (`Recipe_ID`, `Name`, `ID`, `Recipe`, `Start_date`, `End_date`, `const1`, `const2`, `const3`) 
                                 VALUES (NULL, '".$name."', '".$ID."', '".$recipe."', '".$timestamp."', NULL, '".$const1."', '".$const2."', '".$const3."')";
        $entry_result = mysqli_query($conn, $entry_recipe_table_sql) or die(mysqli_error($conn));
        $recipe_id++;
        }
    //now select all entries with resetflag again
    $q_sql = "Select Timestamp,Name,Recipe_ID FROM Data WHERE ResetFlag = '1' ORDER BY Timestamp ASC";
    $result = mysqli_query($conn, $q_sql) or die(mysqli_error($conn));
    //and work on these entries one by one
    while ($row = mysqli_fetch_array($result)){
        $Timestamp = $row[0];
        $Name = $row[1];
        $Recipe_ID = $row[2];
        // select the current entry and the next for this particular SPindle with a reset 
        $timestamp_sql="SELECT Timestamp,Recipe FROM Data WHERE Name= '".$Name."' AND Timestamp >= '$Timestamp' AND ResetFlag = '1' ORDER BY Timestamp ASC limit 2";
        $timestamp_result = mysqli_query($conn, $timestamp_sql) or die(mysqli_error($conn));
        $timestamp_rows = mysqli_num_rows($timestamp_result);
        $timestamp_array = mysqli_fetch_array($timestamp_result);
        //define start time to write Recipe_ID
        $timestamp_1 = $timestamp_array[0];
        $recipe = $timestamp_array[1];
        //define end time for Recipe_ID if not last entry in database for this spindle
        if ($timestamp_rows == 2 ){
            $timestamp_array = mysqli_fetch_array($timestamp_result);
            $timestamp_2 = $timestamp_array[0];
        }
        // if no further reset flag available, use current time
        else {
            $timestamp_2 = date("Y-m-d H:i:s");
        }
        $rolloutID_SQL = "UPDATE Data Set Recipe_ID = '".$Recipe_ID."' WHERE NAME = '".$Name."' AND Timestamp BETWEEN '".$timestamp_1."' AND '".$timestamp_2."'";
        $rolloutID_result = mysqli_query($conn, $rolloutID_SQL) or die(mysqli_error($conn));
        $update_archive_table = "UPDATE Archive Set End_date = '".$timestamp_2."' WHERE Recipe_ID = '".$Recipe_ID."'";
        $update_archive_result = mysqli_query($conn, $update_archive_table) or die(mysqli_error($conn));

    }
        echo "Table modified";
    }
    set_time_limit($max_time);

}


function upgrade_strings_table($conn)
{
    $upgrade                    = false;
    $file_version               = LATEST_STRINGS_TABLE;
    $file_name                  = "../Strings_".$file_version.".sql";


    $q_sql="Select Field from Strings WHERE File = 'Version'";
    $result = mysqli_query($conn, $q_sql) or die(mysqli_error($conn)); 
    $rows = mysqli_num_rows($result);
    if($rows > 0) {
        $row = mysqli_fetch_array($result);
        $value = $row[0];
        if (intval($value) == intval($file_version)) {
            echo 'Latest Version installed:'.intval($value);
            exit;
        }
        if (($value == '') || (intval($value) < intval($file_version))){
            $upgrade = true;
        }
    }
    else {
        // No Parameter in Database -> upgrade to newer version. Only older versions do have no version informatrion
        $upgrade = true;
    }
    if ($upgrade == true){
    import_table($conn,'Strings',$file_name);
    }
}

function upgrade_settings_table($conn)
{
    $upgrade                    = false;
    $file_version               = LATEST_SETTINGS_TABLE;
    $file_name                  = "../Settings_".$file_version.".sql";


    $q_sql="Select value from Settings WHERE Section = 'VERSION'";
    $result = mysqli_query($conn, $q_sql) or die(mysqli_error($conn));
    $rows = mysqli_num_rows($result);
    if($rows > 0) {
        $row = mysqli_fetch_array($result);
        $value = $row[0];
        if (intval($value) == intval($file_version)) {
            echo 'Latest Version installed:'.intval($value);
            exit;
        }
        if (($value == '') || (intval($value) < intval($file_version))){
            $upgrade = true;
        }
    }
    else {
        // No Parameter in Database -> upgrade to newer version. Only older versions do have no version informatrion
        $upgrade = true;
    }
    if ($upgrade == true){
    import_table($conn,'Settings',$file_name);
    }
}

function delete_rid_flag_from_archive($conn,$selected_recipe)
{
    $delete_query = "UPDATE Data Set Internal = NULL WHERE Recipe_ID = '$selected_recipe' AND Internal = 'RID_END'";
    write_log("SELECT to delete RID_END flag:" . $delete_query);
    $result = mysqli_query($conn, $delete_query) or die(mysqli_error($conn));
}


function  delete_recipe_from_archive($conn,$selected_recipe)
{
    $delete_query1 = "DELETE FROM Archive WHERE Recipe_ID = '$selected_recipe'";
    write_log("SELECT to delete recipe from archive table" . $delete_query1);
    $result = mysqli_query($conn, $delete_query1) or die(mysqli_error($conn));
    $delete_query2 = "DELETE FROM Data WHERE Recipe_ID = '$selected_recipe'";
    write_log("SELECT to delete recipe from data table" . $delete_query2);
    $result = mysqli_query($conn, $delete_query2) or die(mysqli_error($conn));
}


function export_data_table($table,$file="iSpindle_Backup.sql")
{
//ENTER THE RELEVANT INFO BELOW
    $user               = DB_USER;
    $pass               = DB_PASSWORD;
    $host               = DB_SERVER; 
    $name               = DB_NAME;
    $port               = DB_PORT;
    $backup_name        = $file;
    if(count($table) == 1){
    $tables              = array($table,"none");
    }  
    else {
    $tables             = $table;
    }
   //or add 5th parameter(array) of specific tables:    array("mytable1","mytable2","mytable3") for multiple tables


    {
        $mysqli = new mysqli($host,$user,$pass,$name,$port); 
        $mysqli->select_db($name); 
        $mysqli->query("SET NAMES 'utf8'");

        $queryTables    = $mysqli->query('SHOW TABLES'); 
        while($row = $queryTables->fetch_row()) 
        { 
            $target_tables[] = $row[0]; 
        }   
        if($tables !== false) 
        { 
            $target_tables = array_intersect( $target_tables, $tables); 
        }
        foreach($target_tables as $table)
        {
            $result         =   $mysqli->query('SELECT * FROM '.$table);  
            $fields_amount  =   $result->field_count;  
            $rows_num=$mysqli->affected_rows;     
            $res            =   $mysqli->query('SHOW CREATE TABLE '.$table); 
            $TableMLine     =   $res->fetch_row();
            $content        = (!isset($content) ?  '' : $content) . "\n\n".$TableMLine[1].";\n\n";

            for ($i = 0, $st_counter = 0; $i < $fields_amount;   $i++, $st_counter=0) 
            {
                while($row = $result->fetch_row())  
                { //when started (and every after 100 command cycle):
                    if ($st_counter%100 == 0 || $st_counter == 0 )  
                    {
                            $content .= "\nINSERT INTO ".$table." VALUES";
                    }
                    $content .= "\n(";
                    for($j=0; $j<$fields_amount; $j++)  
                    { 
                        $row[$j] = str_replace("\n","\\n", addslashes($row[$j]) ); 
                        if (isset($row[$j]))
                        {
                            $content .= '"'.$row[$j].'"' ; 
                        }
                        else 
                        {   
                            $content .= '""';
                        }     
                        if ($j<($fields_amount-1))
                        {
                                $content.= ',';
                        }      
                    }
                    $content .=")";
                    //every after 100 command cycle [or at last line] ....p.s. but should be inserted 1 cycle eariler
                    if ( (($st_counter+1)%100==0 && $st_counter!=0) || $st_counter+1==$rows_num) 
                    {   
                        $content .= ";";
                    } 
                    else 
                    {
                        $content .= ",";
                    } 
                    $st_counter=$st_counter+1;
                }
            } $content .="\n\n\n";
        }
        //$backup_name = $backup_name ? $backup_name : $name."___(".date('H-i-s')."_".date('d-m-Y').")__rand".rand(1,11111111).".sql";
        $backup_name = $backup_name ? $backup_name : $name.".sql";
        header('Content-Type: application/octet-stream');   
        header("Content-Transfer-Encoding: Binary"); 
        header("Content-disposition: attachment; filename=\"".$backup_name."\"");  
        echo $content; exit;
    }
}


function import_table($conn,$table,$filename)
{
// Drop table first
$drop_table="DROP TABLE IF EXISTS ".$table;
$result = mysqli_query($conn, $drop_table) or die(mysqli_error($conn));

$auto_increment="SET sql_mode='NO_AUTO_VALUE_ON_ZERO'";
$result = mysqli_query($conn, $auto_increment) or die(mysqli_error($conn));

// Temporary variable, used to store current query
$templine = '';
// Read in entire file
$lines = file($filename);
// Loop through each line
foreach ($lines as $line)
{
// Skip it if it's a comment
if (substr($line, 0, 2) == '--' || $line == '')
    continue;

// Add this line to the current segment
$templine .= $line;
// If it has a semicolon at the end, it's the end of the query
if (substr(trim($line), -1, 1) == ';')
{
    // Perform the query
    mysqli_query($conn,$templine) or print('Error performing query \'<strong>' . $templine . '\': ' . mysql_error() . '<br /><br />');
    // Reset temp variable to empty
    $templine = '';
}
}
 echo "Tables imported successfully";
}

function export_settings($conn,$table='Settings',$filename)
{
    $q_sql="Select Section, Parameter, value, DeviceName from $table WHERE Parameter NOT LIKE 'Sent%' AND Section NOT LIKE 'VERSION' ORDER by DeviceName";
    $result = mysqli_query($conn, $q_sql) or die(mysqli_error($conn));
    $fp = fopen('php://output', 'w');
    if ($fp && $result) 
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename='.$filename);
        header('Pragma: no-cache');
        header('Expires: 0');
        while ($row = $result->fetch_array(MYSQLI_NUM)) 
        {
            fputcsv($fp, array_values($row));
        }
    die;
    }   

}

function import_settings($conn,$table='Settings',$filename)
{
$Devices='';
$settings_table_exists="SHOW TABLES LIKE '".$table."'";
$result = mysqli_query($conn, $settings_table_exists) or die(mysqli_error($conn));

$delete_other_devices="DELETE FROM Settings WHERE DeviceName <> 'GLOBAL' AND DeviceName <> '_DEFAULT'";
$result = mysqli_query($conn, $delete_other_devices) or die(mysqli_error($conn));

$file = fopen($filename, "r");
$i=0;
    while (($column = fgetcsv($file, 10000, ",")) !== FALSE) 
        {
        if ($column[0]<>"")
            {
            if ($column[3]=='GLOBAL' || $column[3] == '_DEFAULT')
                {
                $column[2]= str_replace('\\', '\\\\', $column[2]);
                $sqlUpdate = "UPDATE $table SET value = '$column[2]' WHERE DeviceName = '$column[3]' AND Section = '$column[0]' AND Parameter = '$column[1]'";
                $result = mysqli_query($conn, $sqlUpdate) or die(mysqli_error($conn));
                }
                
            if ($column[3] != 'GLOBAL' && $column[3] != '_DEFAULT')
                {
                if ($i==0)
                    {
                    $Devices=array($column[3]);
                    $i++;
                    }
                else 
                    {
                    if (!in_array($column[3],$Devices))
                        {
                        array_push($Devices,$column[3]);
                        }
                    }
                }
            }
        
        }
    if($Devices !='') {
        foreach($Devices as $Device)
        {
            CopySettingsToDevice($conn, $Device);
        } 
        $file = fopen($filename, "r");
        while (($column = fgetcsv($file, 10000, ",")) !== FALSE)
        { 
            if ($column[0]<>"")
            {
                if ($column[3] != 'GLOBAL' && $column[3] != '_DEFAULT')
                {
                    $sqlUpdate = "UPDATE $table SET value = '$column[2]' WHERE DeviceName = '$column[3]' AND Section = '$column[0]' AND Parameter = '$column[1]'";
                    $result = mysqli_query($conn, $sqlUpdate) or die(mysqli_error($conn));
                }
            }
        }
    }
 echo "Settings imported successfully";
}

function getChartValuesperdayrpi($conn, $iSpindleID = 'iSpindel000', $timeFrameHours = defaultTimePeriod, $reset = defaultReset)
{
    $isCalibrated = 0; // is there a calbration record for this iSpindle?
    $valCarbondioxide = '';
    $valTemperature = '';
    $valDens = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;
    if ($reset) {
        $where = "WHERE Name = '" . $iSpindleID . "' 
            AND Timestamp >= (Select max(Timestamp) FROM Data  WHERE ResetFlag = true AND Name = '" . $iSpindleID . "')";
    } else {
        $where = "WHERE Name = '" . $iSpindleID . "' 
            AND Timestamp >= date_sub(NOW(), INTERVAL " . $timeFrameHours . " HOUR) 
            AND Timestamp <= NOW()";
    }

    //$q_sql = mysqli_query($conn, "SELECT UNIX_TIMESTAMP(Timestamp) as unixtime, temperature, angle, recipe
    //                       FROM Data " . $where . " ORDER BY Timestamp ASC") or die(mysqli_error($conn));
                           
    $q_sql = mysqli_query($conn, "SELECT UNIX_TIMESTAMP(Timestamp) as unixtime,MAX(Angle) as max, MIN(Angle) as min, MIN(Temperature) as min_temp, MAX(Temperature) as max_temp, AVG(Temperature) as avg_temp FROM Data " . $where . " GROUP BY DATE_FORMAT(Timestamp, '%Y%m%d') ORDER BY Timestamp ASC") or die(mysqli_error($conn));
     
    // retrieve number of rows
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        // get unique hardware ID for calibration
        $u_sql = mysqli_query($conn, "SELECT ID FROM Data WHERE Name = '" . $iSpindleID . "' ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));
        $rowsID = mysqli_num_rows($u_sql);
        if ($rowsID > 0) {
            // try to get calibration for iSpindle hardware ID
            $r_id = mysqli_fetch_array($u_sql);
            $uniqueID = $r_id['ID'];
            $f_sql = mysqli_query($conn, "SELECT const1, const2, const3 FROM Calibration WHERE ID = '$uniqueID' ") or die(mysqli_error($conn));
            $rows_cal = mysqli_num_rows($f_sql);
            if ($rows_cal > 0) {
                $isCalibrated = 1;
                $r_cal = mysqli_fetch_array($f_sql);
                $const1 = $r_cal['const1'];
                $const2 = $r_cal['const2'];
                $const3 = $r_cal['const3'];
            }
        }
        $Min_temp_val = 999;
        $Max_temp_val = 0;
        $Min_diff_val = 999;
        $Max_diff_val = 0;
        // retrieve and store the values as CSV lists for HighCharts
        while ($r_row = mysqli_fetch_array($q_sql)) {
            $jsTime = $r_row['unixtime'] * 1000;
            $min_angle = $r_row['min'];
            $max_angle = $r_row['max'];
            $min = $const1 * pow($min_angle, 2) + $const2 * $min_angle + $const3; // complete polynome from database
            $max = $const1 * pow($max_angle, 2) + $const2 * $max_angle + $const3; // complete polynome from database
            $diff = $max - $min;
            $min_temp = $r_row['min_temp'];
            $max_temp = $r_row['max_temp'];
            $avg_temp = $r_row['avg_temp'];
            if($max_temp > $Max_temp_val)
            	$Max_temp_val = $max_temp;
            if($min_temp < $Min_temp_val)
            	$Min_temp_val = $min_temp;	
            if($diff < $Min_diff_val)
            	$Min_diff_val =  $diff;
           	if($diff > $Max_diff_val)
           		$Max_diff_val = $diff;
            //$dens = $const1 * pow($angle, 2) + $const2 * $angle + $const3; // complete polynome from database

            $valrange .= '[' . $jsTime . ', ' . $min_temp . ', ' . $max_temp . '],';
            $valTemperature_avg .= '[' . $jsTime . ', ' . round($avg_temp,2) . '],';
            $valdiff .= '[' . $jsTime . ', ' . round($diff,2) . '],';
            //$valmin .= '{ timestamp: ' . $jsTime . ', value: ' . $min . ", recipe: \"" . $r_row['recipe'] . "\"},";
            //$valmax .= '{ timestamp: ' . $jsTime . ', value: ' . $max . ", recipe: \"" . $r_row['recipe'] . "\"},";
            //$valdiff .= '{ timestamp: ' . $jsTime . ', value: ' . $diff . ", recipe: \"" . $r_row['recipe'] . "\"},";
            //$valTemperature_min .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['min_temp'] . ", recipe: \"" . $r_row['recipe'] . "\"},";
            //$valTemperature_max .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['max_temp'] . ", recipe: \"" . $r_row['recipe'] . "\"},";
            //$valTemperature_avg .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['avg_temp'] . ", recipe: \"" . $r_row['recipe'] . "\"},";



        }
        $Min_temp_val = $Min_temp_val - 0.5;
        $Max_temp_val = $Max_temp_val + 0.5;
        $Max_diff_val = round($Max_diff_val, 2);
        $Min_diff_val = round($Min_diff_val, 2);
        
        return array(
            $valrange,
            $valdiff,
            $valTemperature_avg,
            $Min_temp_val,
            $Max_temp_val,
            $Min_diff_val,
            $Max_diff_val
            
        );
    }
}

function get_field_from_sql($conn, $file, $field)
{
// set connection to utf-8 to display characters like umlauts correctly    
    mysqli_set_charset($conn, "utf8mb4");
// query to get language setting
    $sql_language = mysqli_query($conn, "SELECT value FROM Settings WHERE Section = 'GENERAL' AND Parameter = 'LANGUAGE'") or die(mysqli_error($conn));
    $LANGUAGE = mysqli_fetch_array($sql_language);
// choose corresponding description column for selected language
    $DESCRIPTION = "Description_".$LANGUAGE[0];
    $q_sql = "SELECT " . $DESCRIPTION . " FROM Strings WHERE File = '" . $file. "' and Field = '" . $field . "'";
    $result = mysqli_query($conn, $q_sql) or die(mysqli_error($conn));
    $rows = mysqli_num_rows($result);
    if($rows > 0) {
        $r_row = mysqli_fetch_array($result);
        $return_value = $r_row[0];
        if ($return_value == '') {
            $return_value = 'No description in your Language. Please Edit Strings table.';
            }
        return $return_value;
        }
    else {
        return 'No Parameter in Database';
        }
}

function get_settings_from_sql($conn, $section, $device, $parameter)
{
// set connection to utf-8 to display characters like umlauts correctly
    mysqli_set_charset($conn, "utf8mb4");
    $q_sql = "SELECT value FROM Settings WHERE Section = '" . $section. "' and Parameter = '" . $parameter . "' and ( DeviceName = '_DEFAULT' or DeviceName = '" . $device . "' ) ORDER BY DeviceName DESC LIMIT 1;";
    $result = mysqli_query($conn, $q_sql) or die(mysqli_error($conn));
    $rows = mysqli_num_rows($result);
    if($rows > 0) {
        $r_row = mysqli_fetch_array($result);
        $return_value = $r_row[0];
            }
        return $return_value;
}


// Function to write iSpindel Server settings back to sql database. Function is used by settings.php
function UpdateSettings($conn, $Section, $Device, $Parameter, $value)
{
// added to wite newline for csv file correctly to database    
    $value= str_replace('\\', '\\\\', $value);
    $q_sql = mysqli_query($conn, "UPDATE Settings SET value = '" . $value . "' WHERE Section = '" . $Section . "' AND Parameter = '" . $Parameter . "'" . " AND DeviceName = '" . $Device . "'") or die(mysqli_error($conn));
    return 1;
}

function CopySettingsToDevice($conn, $device)
{
    $sql_select="INSERT INTO Settings(Section,Parameter,value,Description_DE,Description_EN,Description_IT,DeviceName) SELECT Section,Parameter,value,Description_DE,Description_EN,Description_IT,'" . $device . "' FROM Settings WHERE DeviceName ='_DEFAULT'";

   $q_sql = mysqli_query($conn, $sql_select) or die(mysqli_error($conn));
    return 1;
}


// Retrieves timestamp of last dataset for corresponding Spindle. If timestamp is older than timeframehours, false will be returned
// Difference between last available data and selected timeframe is calculated and displayed in diagram to go more days back  
function isDataAvailable($conn, $iSpindleID, $Timeframehours)
{
    $q_sql = mysqli_query($conn, "SELECT MAX(UNIX_TIMESTAMP(Timestamp)) AS Timestamp FROM Data WHERE Name ='" . $iSpindleID . "'") or die(mysqli_error($conn));
    $now = time();
    $startdate = $now - $Timeframehours * 3600;
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        $r_row = mysqli_fetch_array($q_sql);
        $valTimestamp = $r_row['Timestamp'];
        $TimeDiff = $startdate - $valTimestamp;
        $go_back = round(($TimeDiff / (3600 * 24)) + 0.5);
        if ($TimeDiff < 0) {
            $DataAvailable = true;
        } else {
            $DataAvailable = false;
        }
    }
    return array($DataAvailable, $go_back);
}

// Used in calibration.php Values for corresponding SpindleID will be either updated (if already in database) or added to table calibration in SQL database
function setSpindleCalibration($conn, $ID, $Calibrated, $const1, $const2, $const3)
{
// if spindle is calibrated, fields only need to be updated. If not, we need to insert a new row to the calibration database
    if ($Calibrated) {
        $q_sql = mysqli_query($conn, "UPDATE Calibration SET const1 = '" . $const1 . "', const2 = '" . $const2 . "', const3 = '" . $const3 . "' WHERE ID = '" . $ID . "'") or die(mysqli_error($conn));
    } else {
        $q_sql = mysqli_query($conn, "INSERT INTO Calibration (ID, const1, const2, const3) VALUES ('" . $ID . "', '" . $const1 . "', '" . $const2 . "', '" . $const3 . "')") or die(mysqli_error($conn));
    }
    return 1;
}

// Function retrieves 'latest' SpindleID for Spindelname if available. ID is used to query calibration table for existing calibration
// If data is available, parameters will be send to form (calibration.php). If not, Calibration_exists is false and empty values will be returned
function getSpindleCalibration($conn, $iSpindleID = 'iSpindel000')
{
    $q_sql0 = mysqli_query($conn, "SELECT DISTINCT ID FROM Data WHERE Name = '" . $iSpindleID . "'AND (ID <>'' OR ID <>'0') ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));
    if (!$q_sql0) {
        echo "Fehler beim Lesen der ID";
    }
    $valID = '0';
    $Calibration_exists = false;
    $valconst1 = '';
    $valconst2 = '';
    $valconst3 = '';
    $rows = mysqli_num_rows($q_sql0);
    if ($rows > 0) {
        $r_row = mysqli_fetch_array($q_sql0);
        $valID = $r_row['ID'];
        $q_sql1 = mysqli_query($conn, "SELECT const1, const2, const3
                               FROM Calibration WHERE ID = " . $valID) or die(mysqli_error($conn));
        $rows1 = mysqli_num_rows($q_sql1);
        if ($rows1 > 0) {
            $Calibration_exists = true;
            $r_row = mysqli_fetch_array($q_sql1);
            $valconst1 = $r_row['const1'];
            $valconst2 = $r_row['const2'];
            $valconst3 = $r_row['const3'];
        }
    }
    return array(
        $Calibration_exists,
        $valconst1,
        $valconst2,
        $valconst3,
        $valID
    );
}

// get current interval for Spindel to derive number of rows for moving average calculation with sql windows functions
function getCurrentInterval($conn, $iSpindleID)
{
    $q_sql = mysqli_query($conn, "SELECT Data.Interval as frequency
                FROM Data
                WHERE Name = '" . $iSpindleID . "'
                ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));

    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        $r_row = mysqli_fetch_array($q_sql);
        $valInterval = $r_row['frequency'];
        return $valInterval;
    }
}


// remove last character from a string
function delLastChar($string = "")
{
    $t = substr($string, 0, -1);
    return ($t);
}
//Returns name of Recipe for current fermentation - Name can be set with reset.
function getCurrentRecipeName($conn, $iSpindleID = 'iSpindel000', $timeFrameHours = defaultTimePeriod, $reset = defaultReset)
{
    mysqli_set_charset($conn, "utf8mb4");
    $q_sql1 = mysqli_query($conn, "SELECT Data.Recipe, Data.Timestamp FROM Data WHERE Data.Name = '" . $iSpindleID . "' AND Data.Timestamp >= (SELECT max( Data.Timestamp )FROM Data WHERE Data.Name = '" . $iSpindleID . "' AND Data.ResetFlag = true) LIMIT 1") or die(mysqli_error($conn));


    $q_sql2 = mysqli_query($conn, "SELECT Data.Timestamp FROM Data WHERE Data.Name = '" . $iSpindleID . "' AND Timestamp >= date_sub(NOW(), INTERVAL " . $timeFrameHours . " HOUR)                                                                                                                                                                          AND Timestamp <= NOW() LIMIT 1") or die(mysqli_error($conn));

    $rows = mysqli_num_rows($q_sql1);


    if ($rows > 0) {
        $r_row = mysqli_fetch_array($q_sql1);
        $t_row = mysqli_fetch_array($q_sql2);
        $RecipeName = '';
        $showCurrentRecipe = false;
        $TimeFrame = $t_row['Timestamp'];
        $ResetTime = $r_row['Timestamp'];
        if ($reset == true) {
            $RecipeName = $r_row['Recipe'];
            $showCurrentRecipe = true;
        } else {
            if ($ResetTime < $TimeFrame) {
                $RecipeName = $r_row['Recipe'];
                $showCurrentRecipe = true;
            }
        }
        return array(
            $RecipeName,
            $showCurrentRecipe
        );

    }
}

function getCurrentRecipeName_iGauge($conn, $iSpindleID = 'iGauge000', $timeFrameHours = defaultTimePeriod, $reset = defaultReset)
{
    $q_sql1 = mysqli_query($conn, "SELECT iGauge.Recipe, iGauge.Timestamp FROM iGauge WHERE iGauge.Name = '" . $iSpindleID . "' AND iGauge.Timestamp >= (SELECT max( iGauge.Timestamp )FROM iGauge WHERE iGauge.Name = '" . $iSpindleID . "' AND iGauge.ResetFlag = true) LIMIT 1") or die(mysqli_error($conn));


    $q_sql2 = mysqli_query($conn, "SELECT iGauge.Timestamp FROM iGauge WHERE iGauge.Name = '" . $iSpindleID . "' AND Timestamp >= date_sub(NOW(), INTERVAL " . $timeFrameHours . " HOUR)                                                                                                                                                                          AND Timestamp <= NOW() LIMIT 1") or die(mysqli_error($conn));

    $rows = mysqli_num_rows($q_sql1);


    if ($rows > 0) {
        $r_row = mysqli_fetch_array($q_sql1);
        $t_row = mysqli_fetch_array($q_sql2);
        $RecipeName = '';
        $showCurrentRecipe = false;
        $TimeFrame = $t_row['Timestamp'];
        $ResetTime = $r_row['Timestamp'];
        if ($reset == true) {
            $RecipeName = $r_row['Recipe'];
            $showCurrentRecipe = true;
        } else {
            if ($ResetTime < $TimeFrame) {
                $RecipeName = $r_row['Recipe'];
                $showCurrentRecipe = true;
            }
        }
        return array(
            $RecipeName,
            $showCurrentRecipe
        );

    }
}

//Returns name of Recipe for current fermentation - Name can be set with reset.
function getCurrentRecipeName_ids2($conn, $iSpindleID = 'IDS000', $timeFrameHours = defaultTimePeriod, $reset = defaultReset)
{
    $q_sql1 = mysqli_query($conn, "SELECT heizen.Recipe, heizen.Timestamp FROM heizen WHERE heizen.Name = '" . $iSpindleID . "' AND heizen.Timestamp >= (SELECT max( heizen.Timestamp )FROM heizen WHERE heizen.Name = '" . $iSpindleID . "' AND heizen.ResetFlag = true) LIMIT 1") or die(mysqli_error($conn));


    $q_sql2 = mysqli_query($conn, "SELECT heizen.Timestamp FROM heizen WHERE heizen.Name = '" . $iSpindleID . "' AND Timestamp >= date_sub(NOW(), INTERVAL " . $timeFrameHours . " HOUR)                                                                                                                                                                          AND Timestamp <= NOW() LIMIT 1") or die(mysqli_error($conn));

    $rows = mysqli_num_rows($q_sql1);


    if ($rows > 0) {
        $r_row = mysqli_fetch_array($q_sql1);
        $t_row = mysqli_fetch_array($q_sql2);
        $RecipeName = '';
        $showCurrentRecipe = false;
        $TimeFrame = $t_row['Timestamp'];
        $ResetTime = $r_row['Timestamp'];
        if ($reset == true) {
            $RecipeName = $r_row['Recipe'];
            $showCurrentRecipe = true;
        } else {
            if ($ResetTime < $TimeFrame) {
                $RecipeName = $r_row['Recipe'];
                $showCurrentRecipe = true;
            }
        }
        return array(
            $RecipeName,
            $showCurrentRecipe
        );

    }
}

// Get calaculate initial gravity from database for archive. First hour after last reset will be used.
// This can be used to calculate apparent attenuation

function getArchiveInitialGravity($conn, $recipe_id)
{
    $isCalibrated = 0; // is there a calbration record for this iSpindle?
    $valAngle = '';
    $valDens = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;
    $where = "WHERE Recipe_ID = $recipe_id AND Timestamp > (Select MAX(Data.Timestamp) FROM Data WHERE Data.ResetFlag = true AND Recipe_id = $recipe_id) 
              AND Timestamp < DATE_ADD((SELECT MAX(Data.Timestamp)FROM Data WHERE Recipe_ID = $recipe_id AND Data.ResetFlag = true), INTERVAL 1 HOUR)";

    $q_sql = mysqli_query($conn, "SELECT AVG(Data.Angle) as angle FROM Data " . $where ) or die(mysqli_error($conn));

    // retrieve number of rows
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        // try to get calibration for recipe_id from archive
        $cal_sql = mysqli_query($conn, "SELECT const1, const2, const3 FROM Archive WHERE Recipe_ID = $recipe_id") or die(mysqli_error($conn));
        $rows_cal = mysqli_num_rows($cal_sql);
        if ($rows_cal > 0) {
            $isCalibrated = 1;
            $r_cal = mysqli_fetch_array($cal_sql);
            $const1 = $r_cal['const1'];
            $const2 = $r_cal['const2'];
            $const3 = $r_cal['const3'];
        }
    }
    $r_row = mysqli_fetch_array($q_sql);
    $angle = $r_row['angle'];
    $dens = round(($const1 * pow($angle, 2) + $const2 * $angle + $const3),2); // complete polynome from database
    return array(
        $isCalibrated,
        $dens,
        $const1,
        $const2,
        $const3
    );
}

// Get calaculate final gravity from database for archive. last hour will be used.
// This can be used to calculate apparent attenuation

function getArchiveFinalGravity($conn, $recipe_id, $end_date)
{
    $isCalibrated = 0; // is there a calbration record for this iSpindle?
    $valAngle = '';
    $valDens = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;

    $where = "WHERE Recipe_id = $recipe_id and Timestamp < '$end_date' and Recipe_id = $recipe_id AND Timestamp > DATE_SUB('$end_date', INTERVAL 1 HOUR)";


    $q_sql = mysqli_query($conn, "SELECT AVG(Data.Angle) as angle FROM Data " . $where ) or die(mysqli_error($conn));

    // retrieve number of rows
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        // try to get calibration for recipe_id from archive
        $cal_sql = mysqli_query($conn, "SELECT const1, const2, const3 FROM Archive WHERE Recipe_ID = $recipe_id") or die(mysqli_error($conn));
        $rows_cal = mysqli_num_rows($cal_sql);
        if ($rows_cal > 0) {
            $isCalibrated = 1;
            $r_cal = mysqli_fetch_array($cal_sql);
            $const1 = $r_cal['const1'];
            $const2 = $r_cal['const2'];
            $const3 = $r_cal['const3'];
        }
    }
    $r_row = mysqli_fetch_array($q_sql);
    $angle = $r_row['angle'];
    $dens = round(($const1 * pow($angle, 2) + $const2 * $angle + $const3),2); // complete polynome from database
    return array(
        $isCalibrated,
        $dens
    );
}


// Get calaculate initial gravity from database after last reset. First two hours after last reset will be used. 
// This can be used to calculate apparent attenuation in svg_ma.php

function getInitialGravity($conn, $iSpindleID = 'iSpindel000')
{
    $isCalibrated = 0; // is there a calbration record for this iSpindle?
    $valAngle = '';
    $valDens = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;
    $where = "WHERE Name = '" . $iSpindleID . "'
              AND Timestamp > (Select MAX(Data.Timestamp) FROM Data  WHERE Data.ResetFlag = true AND Data.Name = '" . $iSpindleID . "') 
              AND Timestamp < DATE_ADD((SELECT MAX(Data.Timestamp)FROM Data WHERE Data.Name = '" . $iSpindleID . "' 
              AND Data.ResetFlag = true), INTERVAL 1 HOUR)";

    $q_sql = mysqli_query($conn, "SELECT AVG(Data.Angle) as angle FROM Data " . $where ) or die(mysqli_error($conn));

    // retrieve number of rows
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        // get unique hardware ID for calibration
        $u_sql = mysqli_query($conn, "SELECT ID FROM Data WHERE Name = '" . $iSpindleID . "' ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));
        $rowsID = mysqli_num_rows($u_sql);
        if ($rowsID > 0) {
            // try to get calibration for iSpindle hardware ID
            $r_id = mysqli_fetch_array($u_sql);
            $uniqueID = $r_id['ID'];
            $f_sql = mysqli_query($conn, "SELECT const1, const2, const3 FROM Calibration WHERE ID = '$uniqueID' ") or die(mysqli_error($conn));
            $rows_cal = mysqli_num_rows($f_sql);
            if ($rows_cal > 0) {
                $isCalibrated = 1;
                $r_cal = mysqli_fetch_array($f_sql);
                $const1 = $r_cal['const1'];
                $const2 = $r_cal['const2'];
                $const3 = $r_cal['const3'];
            }
        }
        $r_row = mysqli_fetch_array($q_sql);
            $angle = $r_row['angle'];
            $dens = round(($const1 * pow($angle, 2) + $const2 * $angle + $const3),2); // complete polynome from database
        return array(
            $isCalibrated,
            $dens
        );
    }
}

// Check if alarm mail has been sent
function check_mail_sent($conn, $alarm, $iSpindel)
{
        $sqlselect = "Select value from Settings where Section ='EMAIL' and Parameter = '" . $alarm . "' AND value = '" . $iSpindel . "' ;";
        $q_sql = mysqli_query($conn, $sqlselect) or die(mysqli_error($conn));
        if (! $q_sql)
	    {
            return 0;
            } 
        else
            {
            return 1;
            }
}

function delete_mail_sent($conn, $alarm, $iSpindel)
{
        $sqlselect = "DELETE FROM Settings where Section ='EMAIL' and Parameter = '" . $alarm . "' AND value = '" . $iSpindel . "' ;";
        $q_sql = mysqli_query($conn, $sqlselect) or die(mysqli_error($conn));
        if (! $q_sql)
            {
            return 0;
            }
        else
            {
            return 1;
            }
}

// Export values from database for selected spindle, between now and timeframe in hours ago
function ExportArchiveValues($conn, $recipe_ID, $txt_recipe_name, $txt_end, $txt_initial_gravity, $initial_gravity, $txt_final_gravity, $final_gravity, $txt_attenuation, $attenuation, $txt_alcohol, $alcohol, $txt_calibration)
{
    $valAngle = '';
    $valTemperature = '';
    $valDens = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;
    $AND_RID = '';

    $archive_sql = "Select * FROM Archive WHERE Recipe_ID = '$recipe_ID'";
    mysqli_set_charset($conn, "utf8mb4");
    $result = mysqli_query($conn, $archive_sql) or die(mysqli_error($conn));
    $archive_result = mysqli_fetch_array($result);
    $spindle_name = $archive_result['Name'];
    $recipe_name = $archive_result['Recipe'];
    $start_date = $archive_result['Start_date'];
    $end_date = $archive_result['End_date'];
    $const1 = $archive_result['const1'];
    $const2 = $archive_result['const2'];
    $const3 = $archive_result['const3'];

    $sql_IG=floatval($initial_gravity);


    if($end_date == '0000-00-00 00:00:00'){
    $get_end_date = "SELECT max(Timestamp) FROM Data WHERE Recipe_ID = '$recipe_ID'";
    $q_sql = mysqli_query($conn, $get_end_date) or die(mysqli_error($conn));
    $result = mysqli_fetch_array($q_sql);
    $end_date = $result[0];
    }

    $check_RID_END = "SELECT * FROM Data WHERE Recipe_ID = '$recipe_ID' AND Internal = 'RID_END'";
    $q_sql = mysqli_query($conn, $check_RID_END) or die(mysqli_error($conn));
    $rows = mysqli_fetch_array($q_sql);
    if ($rows <> 0)
    {
    $end_date = $rows['Timestamp'];
    $AND_RID = " AND Timestamp <= (Select max(Timestamp) FROM Data WHERE Recipe_ID='$recipe_ID' AND Internal = 'RID_END')";
    }

    $q_sql = mysqli_query($conn, "SELECT Timestamp, Name, ID, Angle, Temperature, Battery, Gravity AS Spindle_Gravity, ($const1*Angle*Angle + $const2*Angle + $const3) AS Calculated_Gravity, 
                                  (($sql_IG-($const1*Angle*Angle + $const2*Angle + $const3))*100 / $sql_IG) AS Attenuation, RSSI, Recipe, Comment
                                  FROM Data WHERE Recipe_ID = '$recipe_ID'" . $AND_RID . " ORDER BY Timestamp ASC") or die(mysqli_error($conn));
    // filename for download
    $filename = $recipe_ID . "_" . date_format(date_create($start_date),'Y_m_d') ."_" . $spindle_name . "_" . $recipe_name . ".txt";
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $flag = false;
    $start_date=date_format(date_create($start_date),'Y-m-d');
    $end_date=date_format(date_create($end_date),'Y-m-d');

    echo "Device: $spindle_name | $txt_recipe_name $recipe_name | Start: $start_date | $txt_end : $end_date \r\n";
    echo "$txt_initial_gravity : $initial_gravity °P | $txt_final_gravity : $final_gravity °P | $txt_attenuation : $attenuation % | $txt_alcohol : $alcohol Vol% \r\n";
    printf("$txt_calibration :  %01.5f * tilt %+01.5f * tilt^2 %+01.5f \r\n",$const1,$const2,$const3);
    echo "\r\n";
    // retrieve and store the values as CSV lists for HighCharts
    while ($row = mysqli_fetch_assoc($q_sql)) {
        if(!$flag) {
            // display field/column names as first row
            echo implode(",", array_keys($row)) . "\r\n";
            $flag = true;
        }
        array_walk($row, __NAMESPACE__ . '\cleanData');
        echo implode(",", array_values($row)) . "\r\n";
    }
    exit;
    }

// Get archive values from database for selected recipe_ID. 
function getArchiveValues($conn, $recipe_ID)
{
    $valAngle = '';
    $valTemperature = '';
    $valDens = '';
    $valGravity = '';
    $valRSSI = '';
    $valBattery = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;
    $AND_RID = ''; 

    $archive_sql = "Select * FROM Archive WHERE Recipe_ID = '$recipe_ID'";
    mysqli_set_charset($conn, "utf8mb4");
    $result = mysqli_query($conn, $archive_sql) or die(mysqli_error($conn));
    $archive_result = mysqli_fetch_array($result);
    $spindle_name = $archive_result['Name'];
    $recipe_name = $archive_result['Recipe'];
    $start_date = $archive_result['Start_date'];
    $end_date = $archive_result['End_date'];
    $const1 = $archive_result['const1'];
    $const2 = $archive_result['const2'];
    $const3 = $archive_result['const3'];

    if($end_date == '0000-00-00 00:00:00'){
    $get_end_date = "SELECT max(Timestamp) FROM Data WHERE Recipe_ID = '$recipe_ID'";
    $q_sql = mysqli_query($conn, $get_end_date) or die(mysqli_error($conn));
    $result = mysqli_fetch_array($q_sql);
    $end_date = $result[0];
    }

    $check_RID_END = "SELECT * FROM Data WHERE Recipe_ID = '$recipe_ID' AND Internal = 'RID_END'";
    $q_sql = mysqli_query($conn, $check_RID_END) or die(mysqli_error($conn));
    $rows = mysqli_fetch_array($q_sql);
    if ($rows <> 0)    
    {
    $end_date = $rows['Timestamp'];
    $AND_RID = " AND Timestamp <= (Select max(Timestamp) FROM Data WHERE Recipe_ID='$recipe_ID' AND Internal = 'RID_END')";
    }

    $q_sql = mysqli_query($conn, "SELECT UNIX_TIMESTAMP(Timestamp) as unixtime, temperature, angle, gravity, battery, rssi, recipe, comment
                           FROM Data WHERE Recipe_ID = '$recipe_ID'" . $AND_RID . " ORDER BY Timestamp ASC") or die(mysqli_error($conn));
	$label_position = 1;
        // retrieve and store the values as CSV lists for HighCharts
        while ($r_row = mysqli_fetch_array($q_sql)) {
            $jsTime = $r_row['unixtime'] * 1000;
            $angle = $r_row['angle'];
            $dens = $const1 * pow($angle, 2) + $const2 * $angle + $const3; // complete polynome from database
            $gravity = $r_row['gravity'];
            $rssi = $r_row['rssi'];
            $battery = $r_row['battery'];

            if ($r_row['comment']){
                if($label_position == 1){
                    $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $dens . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $valAngle .= '{ timestamp: ' . $jsTime . ', value: ' . $angle . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $valGravity .= '{ timestamp: ' . $jsTime . ', value: ' . $gravity . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $valBattery .= '{ timestamp: ' . $jsTime . ', value: ' . $battery . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $label_position = $label_position * -1;
                }
                else{
                    $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $dens . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $valAngle .= '{ timestamp: ' . $jsTime . ', value: ' . $angle . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $valGravity .= '{ timestamp: ' . $jsTime . ', value: ' . $gravity . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $valBattery .= '{ timestamp: ' . $jsTime . ', value: ' . $battery . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $label_position = $label_position * -1;
                }
  
            } 
            else{
            $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $dens . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valAngle .= '{ timestamp: ' . $jsTime . ', value: ' . $angle . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valGravity .= '{ timestamp: ' . $jsTime . ', value: ' . $gravity . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valBattery .= '{ timestamp: ' . $jsTime . ', value: ' . $battery . ", recipe: \"" . $r_row['recipe'] . "\"},";
            }
            $valTemperature .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['temperature'] . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valRSSI .= '{ timestamp: ' . $jsTime . ', value: ' . $rssi . ", recipe: \"" . $r_row['recipe'] . "\"},";



        }
        return array(
            $spindle_name,
            $recipe_name,
            $start_date,
            $end_date,
            $valDens,
            $valTemperature,
            $valAngle,
            $valGravity,
            $valBattery,
            $valRSSI
        );
  
}


// Get calibrated values from database for selected spindle, between now and [number of hours] ago
// Old Method for Firmware before 5.x
function getChartValues($conn, $iSpindleID = 'iSpindel000', $timeFrameHours = defaultTimePeriod, $reset = defaultReset)
{
    $isCalibrated = 0; // is there a calbration record for this iSpindle?
    $valAngle = '';
    $valTemperature = '';
    $valDens = '';
    $valGravity = '';
    $valRSSI = '';
    $valBattery = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;

    if ($reset) {
        $where = "WHERE Name = '" . $iSpindleID . "' 
            AND Timestamp >= (Select max(Timestamp) FROM Data  WHERE ResetFlag = true AND Name = '" . $iSpindleID . "')";
    } else {
        $where = "WHERE Name = '" . $iSpindleID . "' 
            AND Timestamp >= date_sub(NOW(), INTERVAL " . $timeFrameHours . " HOUR) 
            AND Timestamp <= NOW()";
    }

    mysqli_set_charset($conn, "utf8mb4");

    $q_sql = mysqli_query($conn, "SELECT UNIX_TIMESTAMP(Timestamp) as unixtime, temperature, angle, recipe, battery, rssi, gravity, comment
                           FROM Data " . $where . " ORDER BY Timestamp ASC") or die(mysqli_error($conn));
    
    // retrieve number of rows
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        // get unique hardware ID for calibration
        $u_sql = mysqli_query($conn, "SELECT ID FROM Data WHERE Name = '" . $iSpindleID . "' ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));
        $rowsID = mysqli_num_rows($u_sql);
        if ($rowsID > 0) {
            // try to get calibration for iSpindle hardware ID
            $r_id = mysqli_fetch_array($u_sql);
            $uniqueID = $r_id['ID'];
            $f_sql = mysqli_query($conn, "SELECT const1, const2, const3 FROM Calibration WHERE ID = '$uniqueID' ") or die(mysqli_error($conn));
            $rows_cal = mysqli_num_rows($f_sql);
            if ($rows_cal > 0) {
                $isCalibrated = 1;
                $r_cal = mysqli_fetch_array($f_sql);
                $const1 = $r_cal['const1'];
                $const2 = $r_cal['const2'];
                $const3 = $r_cal['const3'];
            }
        }
        // retrieve and store the values as CSV lists for HighCharts
        $label_position = 1;
        while ($r_row = mysqli_fetch_array($q_sql)) {
            $jsTime = $r_row['unixtime'] * 1000;
            $angle = $r_row['angle'];
            $dens = $const1 * pow($angle, 2) + $const2 * $angle + $const3; // complete polynome from database
            $gravity = $r_row['gravity'];
            $rssi = $r_row['rssi'];
            $battery = $r_row['battery'];

            if ($r_row['comment']){
                if($label_position == 1){
                    $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $dens . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $valAngle .= '{ timestamp: ' . $jsTime . ', value: ' . $angle . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $valGravity .= '{ timestamp: ' . $jsTime . ', value: ' . $gravity . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $valBattery .= '{ timestamp: ' . $jsTime . ', value: ' . $battery . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $label_position = $label_position * -1;
                }
                else{
                    $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $dens . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $valAngle .= '{ timestamp: ' . $jsTime . ', value: ' . $angle . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $valGravity .= '{ timestamp: ' . $jsTime . ', value: ' . $gravity . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $valBattery .= '{ timestamp: ' . $jsTime . ', value: ' . $battery . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $label_position = $label_position * -1;
                }

            }
            else{
            $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $dens . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valAngle .= '{ timestamp: ' . $jsTime . ', value: ' . $angle . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valGravity .= '{ timestamp: ' . $jsTime . ', value: ' . $gravity . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valBattery .= '{ timestamp: ' . $jsTime . ', value: ' . $battery . ", recipe: \"" . $r_row['recipe'] . "\"},";
            }
            $valTemperature .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['temperature'] . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valRSSI .= '{ timestamp: ' . $jsTime . ', value: ' . $rssi . ", recipe: \"" . $r_row['recipe'] . "\"},";

        }
        return array(
            $isCalibrated,
            $valDens,
            $valTemperature,
            $valAngle,
            $valGravity,
            $valBattery,
            $valRSSI
        );
    }
}

function getChartValuesiGauge($conn, $iSpindleID = 'iGauge000', $timeFrameHours = defaultTimePeriod, $reset = defaultReset)
{
    $isCalibrated = 1; // is there a calbration record for this iSpindle?
    $valCarbondioxide = '';
    $valTemperature = '';
    $valpressure = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;
    if ($reset) {
        $where = "WHERE Name = '" . $iSpindleID . "' 
            AND Timestamp >= (Select max(Timestamp) FROM iGauge  WHERE ResetFlag = true AND Name = '" . $iSpindleID . "')";
    } else {
        $where = "WHERE Name = '" . $iSpindleID . "' 
            AND Timestamp >= date_sub(NOW(), INTERVAL " . $timeFrameHours . " HOUR) 
            AND Timestamp <= NOW()";
    }
	//$q_sql = mysqli_query($conn, "SELECT UNIX_TIMESTAMP(Timestamp) as unixtime, Temperature, Pressure , Carbondioxid, recipe
                           //FROM iGauge " . $where . " ORDER BY Timestamp ASC") or die(mysqli_error($conn));
 	
	$q_sql = mysqli_query($conn, "SELECT UNIX_TIMESTAMP(Timestamp) as unixtime, Temperature, Pressure , Carbondioxid, recipe
                           FROM iGauge " . $where . " ORDER BY Timestamp ASC");
    
    // retrieve number of rows
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        // get unique hardware ID for calibration
        $u_sql = mysqli_query($conn, "SELECT ID FROM Data WHERE Name = '" . $iSpindleID . "' ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));
        $rowsID = mysqli_num_rows($u_sql);
        // retrieve and store the values as CSV lists for HighCharts
        while ($r_row = mysqli_fetch_array($q_sql)) {
            $jsTime = $r_row['unixtime'] * 1000;
            $carbondioxixde = $r_row['Carbondioxid'];
            $pressure = $r_row['Pressure']; 

            $valCarbondioxide .= '{ timestamp: ' . $jsTime . ', value: ' . $carbondioxixde . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valpressure .= '{ timestamp: ' . $jsTime . ', value: ' . $pressure . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valTemperature .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['Temperature'] . ", recipe: \"" . $r_row['recipe'] . "\"},";



        }
        return array(
            $isCalibrated,
            $valpressure,
            $valTemperature,
            $valCarbondioxide,
        );
    }
}

function getChartValuesids2($conn, $iSpindleID = 'IDS000', $timeFrameHours = defaultTimePeriod, $reset = defaultReset)
{
    $isCalibrated = 1; // is there a calbration record for this iSpindle?
    $valCarbondioxide = '';
    $valTemperature = '';
    $valpressure = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;
    if ($reset) {
        $where = "WHERE Name = '" . $iSpindleID . "' 
            AND Timestamp >= (Select max(Timestamp) FROM heizen  WHERE ResetFlag = true AND Name = '" . $iSpindleID . "')";
    } else {
        $where = "WHERE Name = '" . $iSpindleID . "' 
            AND Timestamp >= date_sub(NOW(), INTERVAL " . $timeFrameHours . " HOUR) 
            AND Timestamp <= NOW()";
    }

    $q_sql = mysqli_query($conn, "SELECT UNIX_TIMESTAMP(Timestamp) as unixtime, Temperature, Stellgrad , Sollwert, Gradient,Restzeit, recipe
                           FROM heizen " . $where . " ORDER BY Timestamp ASC") or die(mysqli_error($conn));
    
    // retrieve number of rows
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        // get unique hardware ID for calibration
        $u_sql = mysqli_query($conn, "SELECT ID FROM Data WHERE Name = '" . $iSpindleID . "' ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));
        $rowsID = mysqli_num_rows($u_sql);
        // retrieve and store the values as CSV lists for HighCharts
        while ($r_row = mysqli_fetch_array($q_sql)) {
            $jsTime = $r_row['unixtime'] * 1000;
            $gradient = $r_row['Gradient'];
            $Sollwert = $r_row['Sollwert']; 

            $valgradient .= '{ timestamp: ' . $jsTime . ', value: ' . $gradient . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valsollwert .= '{ timestamp: ' . $jsTime . ', value: ' . $Sollwert . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valTemperature .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['Temperature'] . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valStellgrad .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['Stellgrad'] . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valRestzeit .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['Restzeit'] . ", recipe: \"" . $r_row['recipe'] . "\"},";



        }
        return array(
            $isCalibrated,
            $valTemperature,
            $valsollwert,
            $valStellgrad,
            $valgradient,
			$valRestzeit        
        );
    }
}

// Get calibrated gravity value from database for selected spindle
function getlastValuesPlato4($conn, $iSpindleID = 'iSpindel000')
{
    $isCalibrated = 0; // is there a calbration record for this iSpindle?
    $valDens = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;

    mysqli_set_charset($conn, "utf8mb4");

    $q_sql = mysqli_query($conn, "SELECT UNIX_TIMESTAMP(Timestamp) as unixtime, temperature, angle, recipe, battery, 'interval', rssi, gravity
                FROM Data
                WHERE Name = '" . $iSpindleID . "'
                ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));


    // retrieve number of rows
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        // get unique hardware ID for calibration
        $u_sql = mysqli_query($conn, "SELECT ID FROM Data WHERE Name = '" . $iSpindleID . "' ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));
        $rowsID = mysqli_num_rows($u_sql);
        if ($rowsID > 0) {
            // try to get calibration for iSpindle hardware ID
            $r_id = mysqli_fetch_array($u_sql);
            $uniqueID = $r_id['ID'];
            $f_sql = mysqli_query($conn, "SELECT const1, const2, const3 FROM Calibration WHERE ID = '$uniqueID' ") or die(mysqli_error($conn));
            $rows_cal = mysqli_num_rows($f_sql);
            if ($rows_cal > 0) {
                $isCalibrated = 1;
                $r_cal = mysqli_fetch_array($f_sql);
                $const1 = $r_cal['const1'];
                $const2 = $r_cal['const2'];
                $const3 = $r_cal['const3'];
            }
        }
        // retrieve and store the values as CSV lists for HighCharts
        $r_row = mysqli_fetch_array($q_sql);
        $valTime = $r_row['unixtime'];
        $valTemperature = $r_row['temperature'];
        $valAngle = $r_row['angle'];
        $valDens = $const1 * pow($valAngle, 2) + $const2 * $valAngle + $const3; // complete polynome from database
        $valRecipe = $r_row['recipe'];
        $valInterval = $r_row['interval'];
        $valBattery = $r_row['battery'];
        $valRSSI = $r_row['rssi'];
        $valGravity = $r_row['gravity'];
        return array(
            $isCalibrated,
            $valTime,
            $valTemperature,
            $valAngle,
            $valBattery,
            $valRecipe,
            $valDens,
            $valRSSI,
            $valInterval,
            $valGravity
        );
    }
}

function getValuesHoursAgoPlato4($conn, $iSpindleID = 'iSpindel000', $lasttime, $hours)
{
    $isCalibrated = 0; // is there a calbration record for this iSpindle?
    $valDens = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;
    
    mysqli_set_charset($conn, "utf8mb4");
    $select="SELECT UNIX_TIMESTAMP(Timestamp) as unixtime, temperature, angle, recipe, battery, 'interval', rssi, gravity
                FROM Data
                WHERE Name = '" . $iSpindleID . "' AND Timestamp > DATE_SUB(FROM_UNIXTIME($lasttime), INTERVAL $hours HOUR) limit 1";

    write_log($select);

    $q_sql = mysqli_query($conn, $select) or die(mysqli_error($conn));

    // retrieve number of rows
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        // get unique hardware ID for calibration
        $u_sql = mysqli_query($conn, "SELECT ID FROM Data WHERE Name = '" . $iSpindleID . "' ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));
        $rowsID = mysqli_num_rows($u_sql);
        if ($rowsID > 0) {
            // try to get calibration for iSpindle hardware ID
            $r_id = mysqli_fetch_array($u_sql);
            $uniqueID = $r_id['ID'];
            $f_sql = mysqli_query($conn, "SELECT const1, const2, const3 FROM Calibration WHERE ID = '$uniqueID' ") or die(mysqli_error($conn));
            $rows_cal = mysqli_num_rows($f_sql);
            if ($rows_cal > 0) {
                $isCalibrated = 1;
                $r_cal = mysqli_fetch_array($f_sql);
                $const1 = $r_cal['const1'];
                $const2 = $r_cal['const2'];
                $const3 = $r_cal['const3'];
            }
        }
        // retrieve and store the values as CSV lists for HighCharts
        $r_row = mysqli_fetch_array($q_sql);
        $valTime = $r_row['unixtime'];
        $valTemperature = $r_row['temperature'];
        $valAngle = $r_row['angle'];
        $valDens = $const1 * pow($valAngle, 2) + $const2 * $valAngle + $const3; // complete polynome from database
        $valRecipe = $r_row['recipe'];
        $valInterval = $r_row['interval'];
        $valBattery = $r_row['battery'];
        $valRSSI = $r_row['rssi'];
        $valGravity = $r_row['gravity'];
        return array(
            $isCalibrated,
            $valTime,
            $valTemperature,
            $valAngle,
            $valBattery,
            $valRecipe,
            $valDens,
            $valRSSI,
            $valInterval,
            $valGravity
        );
    }
}


function getChartValuesPlato4_delta($conn, $iSpindleID = 'iSpindel000', $timeFrameHours = defaultTimePeriod, $movingtime = 720, $reset = defaultReset)
{
    $Interval = (getCurrentInterval($conn, $iSpindleID));
    $Rows = round($movingtime / ($Interval / 60));
    
    $isCalibrated = 0; // is there a calbration record for this iSpindle?
    $valTemperature = '';
    $valDens = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;
    // get unique hardware ID for calibration
    $u_sql = mysqli_query($conn, "SELECT ID FROM Data WHERE Name = '" . $iSpindleID . "' ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));
    $rowsID = mysqli_num_rows($u_sql);
        if ($rowsID > 0) {
            // try to get calibration for iSpindle hardware ID
            $r_id = mysqli_fetch_array($u_sql);
            $uniqueID = $r_id['ID'];
            $f_sql = mysqli_query($conn, "SELECT const1, const2, const3 FROM Calibration WHERE ID = '$uniqueID' ") or die(mysqli_error($conn));
            $rows_cal = mysqli_num_rows($f_sql);
            if ($rows_cal > 0) {
                $isCalibrated = 1;
                $r_cal = mysqli_fetch_array($f_sql);
                $const1 = $r_cal['const1'];
                $const2 = $r_cal['const2'];
                $const3 = $r_cal['const3'];
            }
        }

    if ($reset) {
        $where = "WHERE Name = '" . $iSpindleID . "'
            AND Timestamp > (Select max(Timestamp) FROM Data  WHERE ResetFlag = true AND Name = '" . $iSpindleID . "')";
    } else {
        $where = "WHERE Name = '" . $iSpindleID . "'
            AND Timestamp >= date_sub(NOW(), INTERVAL " . $timeFrameHours . " HOUR)
            AND Timestamp <= NOW()";
    }
    mysqli_set_charset($conn, "utf8mb4");

         $p_sql = mysqli_query($conn, "SET @x:=0") or die(mysqli_error($conn));
         if($q_sql = mysqli_query($conn, "SELECT * 
                                       FROM (SELECT (@x:=@x+1) AS x, 
                                       UNIX_TIMESTAMP(mt.Timestamp) as unixtime, 
                                       mt.name, 
                                       mt.recipe, 
                                       mt.temperature, 
                                       mt.angle, 
                                       mt.Angle*mt.Angle*" . $const1 . " + mt.Angle*" . $const2 . " + " . $const3 . " AS Calc_Plato, 
                                       mt.Angle*mt.Angle*" . $const1 . "+mt.Angle*" . $const2 . "+" . $const3 . " - lag(mt.Angle*mt.Angle*" . $const1 . "+mt.Angle*" . $const2 . "+" . $const3 . ", " . $Rows . ") 
                                       OVER (ORDER BY mt.Timestamp) DeltaPlato 
                                       FROM Data mt " .$where . " order by Timestamp) t WHERE x MOD " . $Rows . " = 0"))
         {

         // retrieve number of rows
         $rows = mysqli_num_rows($q_sql);
         while ($r_row = mysqli_fetch_array($q_sql)) {
             $jsTime = $r_row['unixtime'] * 1000;
             $Ddens = $r_row['DeltaPlato'];
             if ($Ddens == '') {
                 $Ddens= 0;
             }
             if ($r_row['comment']){
                if($label_position == 1){
                    $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $Ddens . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $label_position = $label_position * -1;
                }
                else{
                    $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $Ddens . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $label_position = $label_position * -1;
                }
             }
             else{
             $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $Ddens . ", recipe: \"" . $r_row['recipe'] . "\"},";
             }
             $valTemperature .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['temperature'] . ", recipe: \"" . $r_row['recipe'] . "\"},";
             }
         return array(
             $isCalibrated,
             $valDens,
             $valTemperature
         );
         }
         else {
             echo "Select for this diagram is using 'SQL Windows functions'. Either your Data table is still empty, or your Database does not seem to support it. If you want to use these functions you need to upgrade to a newer version of your SQL installation.<br/><br/><a href=/iSpindle/index.php><img src=include/icons8-home-26.png></a>";
             exit;
         }
}




// Get calibrated values from database for selected spindle, between now and [number of hours] ago
// Old Method for Firmware before 5.x
function getChartValues_ma($conn, $iSpindleID = 'iSpindel000', $timeFrameHours = defaultTimePeriod, $movingtime, $reset = defaultReset)
{
    $isCalibrated = 0; // is there a calbration record for this iSpindle?
    $valAngle = '';
    $valTemperature = '';
    $valGravity = '';
    $valDens = '';
    $valSVG = '';
    $valABV = '';
    $const1 = 0;
    $const2 = 0;
    $const3 = 0;
    $where_ma = '';

    $Interval = (getCurrentInterval($conn, $iSpindleID));
    $Rows = round($movingtime / ($Interval / 60));
    list($isCalibrated, $InitialGravity) = (getInitialGravity($conn, $iSpindleID));

    if ($reset) {
        $where = "Data.Timestamp > (Select max(Timestamp) FROM Data WHERE Data.Name = '" . $iSpindleID . "' AND Data.ResetFlag = true)";
        $where_oldDB = "WHERE Data1.Name = '" . $iSpindleID . "'
                                                AND Data1.Timestamp > (Select max(Timestamp) FROM Data WHERE Data.Name = '" . $iSpindleID . "' AND Data.ResetFlag = true)";
        $where_ma = "Data2.Timestamp > (Select max(Data2.Timestamp) FROM Data AS Data2  WHERE Data2.ResetFlag = true AND Data2.Name = '" . $iSpindleID . "') AND";
    } else {
        $where = "Data.Timestamp >= date_sub(NOW(), INTERVAL " . $timeFrameHours . " HOUR) AND Data.Timestamp <= NOW()";
        $where_oldDB = "WHERE Data1.Name = '" . $iSpindleID . "'
                                                AND Data1.Timestamp >= date_sub(NOW(), INTERVAL " . $timeFrameHours . " HOUR)
                                                and Data1.Timestamp <= NOW()";
    }
    mysqli_set_charset($conn, "utf8mb4");
    if (!$q_sql = mysqli_query($conn, "SELECT UNIX_TIMESTAMP(Data.Timestamp) as unixtime, Data.temperature, Data.angle, Data.recipe, Data.comment,
                                AVG(Data.Angle) OVER (ORDER BY Data.Timestamp ASC ROWS " . $Rows . " PRECEDING) AS mv_angle, 
                                AVG(Data.gravity) OVER (ORDER BY Data.Timestamp ASC ROWS " . $Rows . " PRECEDING) AS mv_gravity
                                FROM Data WHERE Data.Name = '" . $iSpindleID . "' AND " . $where)) {


             echo "Select for this diagram is using 'SQL Windows functions'. Either your Data table is still empty, or your Database does not seem to support it. If you want to use these functions you need to upgrade to a newer version of your SQL installation.<br/><br/><a href=/iSpindle/index.php><img src=include/icons8-home-26.png></a>";
             exit;
    
}
    
    
    // retrieve number of rows
    $rows = mysqli_num_rows($q_sql);
    if ($rows > 0) {
        // get unique hardware ID for calibration
        $u_sql = mysqli_query($conn, "SELECT ID FROM Data WHERE Name = '" . $iSpindleID . "' ORDER BY Timestamp DESC LIMIT 1") or die(mysqli_error($conn));
        $rowsID = mysqli_num_rows($u_sql);
        if ($rowsID > 0) {
            // try to get calibration for iSpindle hardware ID
            $r_id = mysqli_fetch_array($u_sql);
            $uniqueID = $r_id['ID'];
            $f_sql = mysqli_query($conn, "SELECT const1, const2, const3 FROM Calibration WHERE ID = '$uniqueID' ") or die(mysqli_error($conn));
            $rows_cal = mysqli_num_rows($f_sql);
            if ($rows_cal > 0) {
                $isCalibrated = 1;
                $r_cal = mysqli_fetch_array($f_sql);
                $const1 = $r_cal['const1'];
                $const2 = $r_cal['const2'];
                $const3 = $r_cal['const3'];
            }
        }
        $label_position = 1;
        // retrieve and store the values as CSV lists for HighCharts
        while ($r_row = mysqli_fetch_array($q_sql)) {
            $jsTime = $r_row['unixtime'] * 1000;
            $angle = $r_row['mv_angle'];
            $gravity = $r_row['mv_gravity'];
            $dens = $const1 * pow($angle, 2) + $const2 * $angle + $const3; // complete polynome from database
            // real density differs fro aparent density
            $real_dens = 0.1808 * $InitialGravity + 0.8192 * $dens;
            // calculte apparent attenuation
            $SVG = ($InitialGravity-$dens)*100/$InitialGravity;
            // calculate alcohol by weigth and by volume (fabbier calcfabbier calc for link see above)
            $alcohol_by_weight = ( 100 * ($real_dens - $InitialGravity) / (1.0665 * $InitialGravity - 206.65));
            $alcohol_by_volume = ($alcohol_by_weight / 0.795);

            if ($r_row['comment']){
                if($label_position == 1){
                    $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $dens . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $valAngle .= '{ timestamp: ' . $jsTime . ', value: ' . $angle . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $valGravity .= '{ timestamp: ' . $jsTime . ', value: ' . $gravity . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $valSVG .= '{ timestamp: ' . $jsTime . ', value: ' . $SVG . ", recipe: \"" . $r_row['recipe'] . "\", text_up: '" . $r_row['comment'] . "'},";
                    $label_position = $label_position * -1;
                }
                else{
                    $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $dens . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $valAngle .= '{ timestamp: ' . $jsTime . ', value: ' . $angle . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $valGravity .= '{ timestamp: ' . $jsTime . ', value: ' . $gravity . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $valSVG .= '{ timestamp: ' . $jsTime . ', value: ' . $SVG . ", recipe: \"" . $r_row['recipe'] . "\", text_down: '" . $r_row['comment'] . "'},";
                    $label_position = $label_position * -1;
                }

            }
            else{
            $valDens .= '{ timestamp: ' . $jsTime . ', value: ' . $dens . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valAngle .= '{ timestamp: ' . $jsTime . ', value: ' . $angle . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valGravity .= '{ timestamp: ' . $jsTime . ', value: ' . $gravity . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valSVG .= '{ timestamp: ' . $jsTime . ', value: ' . $SVG . ", recipe: \"" . $r_row['recipe'] . "\"},";
            }
            $valTemperature .= '{ timestamp: ' . $jsTime . ', value: ' . $r_row['temperature'] . ", recipe: \"" . $r_row['recipe'] . "\"},";
            $valABV .= '{ timestamp: ' . $jsTime . ', value: ' . $alcohol_by_volume . ", recipe: \"" . $r_row['recipe'] . "\"},";

        }

        return array(
            $isCalibrated,
            $valDens,
            $valTemperature,
            $valAngle,
            $valGravity,
            $valSVG,
            $valABV
        );
    }
}

?>
