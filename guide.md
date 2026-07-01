CREATED: admin@inventory.com / Admin@123  (role: admin)
CREATED: staff@inventory.com / Staff@123  (role: staff)
CREATED: supervisor@inventory.com / Super@123  (role: supervisor)

product page:
Fatal error: Uncaught PDOException: SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'out FROM products WHERE status='active'' at line 5 in C:\xampp\htdocs\InventoryManagement\pages\products\index.php:55 Stack trace: #0 C:\xampp\htdocs\InventoryManagement\pages\products\index.php(55): PDO->query('\r\n SELECT\r\n ...') #1 {main} thrown in C:\xampp\htdocs\InventoryManagement\pages\products\index.php on line 55

remove restock option from staff

manual order status change dropdown options
confirm all button in order for admin and supervisor that, if order status = dispatched, the order status changes to confirmed
for order status, add these drop down options:
new
dispatched