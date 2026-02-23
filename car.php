<?php
class Car {
    private $conn;
    private $table = "cars";

    public $id;
    public $brand;
    public $model;
    public $year;
    public $price;
    public $user_id;

    public function __construct($db){ $this->conn = $db; }

    public function create(){
        $query = "INSERT INTO ".$this->table." SET brand=:brand, model=:model, year=:year, price=:price, user_id=:user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":brand",$this->brand);
        $stmt->bindParam(":model",$this->model);
        $stmt->bindParam(":year",$this->year);
        $stmt->bindParam(":price",$this->price);
        $stmt->bindParam(":user_id",$this->user_id);
        return $stmt->execute();
    }

    public function readByUser(){
        $query = "SELECT * FROM ".$this->table." WHERE user_id=:user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id",$this->user_id);
        $stmt->execute();
        return $stmt;
    }
}
?>