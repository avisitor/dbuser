<?php
namespace Common\User;

use Common\DB\DBBase;

class DBUser extends DBBase {
    public function __construct(...$args) { parent::__construct(...$args); $this->table='users'; $this->prefix='user'; }
    public function getFieldNames(): array { return ['id','username','name','active','rate']; }
    public function getByEmail($email,$fromdate=''){ return $this->getBy('username',$email,false); }
    public function getAdminEmails(){
        $sql="select username email from users u,tags t where u.username = t.email and t.tag = 'Admin'";
        return $this->query($sql);
    }
}
