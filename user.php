<?php
class User {
    private $conn;
    private $table = "users";

    public $id;
    public $name;
    public $email;
    public $password;
    public $role;

    public function __construct($db){ $this->conn = $db; }

    public function create(){
        $query = "INSERT INTO ".$this->table." SET name=:name, email=:email, password=:password, role=:role";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":name",$this->name);
        $stmt->bindParam(":email",$this->email);
        $stmt->bindParam(":password",$this->password);
        $stmt->bindParam(":role",$this->role);
        return $stmt->execute();
    }

    public function findByEmail($email){
        $query = "SELECT * FROM ".$this->table." WHERE email=:email";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":email",$email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>