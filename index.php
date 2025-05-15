<?php
require 'config.php';

if (is_login()) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}
exit;
