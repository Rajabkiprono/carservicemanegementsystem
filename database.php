<?php
class Database {
    private $host = "localhost";
    private $db_name = "casms_db";
    private $username = "root";
    private $password = "";
    public $conn;

    public function connect(){
        $this->conn = null;
        try{
            $this->conn = new PDO("mysql:host=".$this->host.";dbname=".$this->db_name,
                                   $this->username,$this->password);
            $this->conn->exec("set names utf8");
        }catch(PDOException $e){
            echo "Database Error: ".$e->getMessage();
        }
        return $this->conn;
    }
}
?>