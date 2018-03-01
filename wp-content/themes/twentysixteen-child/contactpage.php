<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, intialscale=1">
</head>
<body>
	<div class="contact">
<h1>Feel free to send me a message!</h1>

	<div class="form-section">
		<fieldset>
		<h2>Let's conspire to inspire</h2>
			
		<form method="POST" action="contact_action.php">
		
		<p>
			<label>Your name?</label><br>
			<input type="text" name="user_name" placeholder="User Name" required >
		</p>
		
		<p>
			<label>Your email?</label><br>
			<input type="email" name="user_email" placeholder="Email address" required>
		</p>

		<p>
			<form>
				Comment:<br>
				<input type="text" name="comment">
			</form>
		</p>
		
			<p><input type="submit" value="Submit"></p>
		</form>
		</fieldset>
	</div>
</div>