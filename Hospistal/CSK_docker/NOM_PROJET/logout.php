<?php
require_once 'config/config.php';

session_destroy();
session_start();
session_regenerate_id(true);

header("Location: " . BASE_URL . "login.php");
exit();
