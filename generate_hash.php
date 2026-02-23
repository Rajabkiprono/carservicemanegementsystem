<?php
// Run this script once to generate password hashes for your users
echo "Password hash for 'Admin@123': " . password_hash('Admin@123', PASSWORD_DEFAULT) . "\n";
echo "Password hash for 'Finance@123': " . password_hash('Finance@123', PASSWORD_DEFAULT) . "\n";
?>