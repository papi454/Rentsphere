<?php
/**
 * RentSphere - Database Connection (PDO)
 */

class Database
{
    private static ?PDO $instance = null;

    // ---- EDIT THESE FOR YOUR LOCAL SETUP ----
    private static string $host = '127.0.0.1';
    private static string $dbname = 'rentsphere';
    private static string $username = 'root';
    private static string $password = 'FabianMCL'; // your MySQL root password (XAMPP default is empty)
    private static string $charset = 'utf8mb4';
    // ------------------------------------------

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . self::$host . ";dbname=" . self::$dbname . ";charset=" . self::$charset;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                self::$instance = new PDO($dsn, self::$username, self::$password, $options);
            } catch (PDOException $e) {
                error_log('DB Connection Error: ' . $e->getMessage());
                die('Database connection failed. Please check your configuration or contact the administrator.');
            }
        }
        return self::$instance;
    }
}
