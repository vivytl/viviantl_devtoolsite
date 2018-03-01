<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>Nice meeting you!</title>
	<link rel="stylesheet" href="../css/style.css">
</head>
<body>
	<div class="action-section">
		<h1 align="center">Thank you for your submission!</h1>

<pre>
	<?php
		echo print_r($_POST);
	?>
</pre>

<?php

	$UserName=$_POST["user_name"];
	$UserEmail=$_POST["user_email"];
?>

		<p align="center">Your message has been sent.
		</p>

	</div>
</body>
</html>