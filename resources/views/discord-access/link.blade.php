<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Link Discord</title>
</head>
<body>
    <p>Link this Discord account to the Wormhole Systems user you are signed in as. This does not grant access by nickname.</p>
    <form method="POST" action="{{ $action }}">
        @csrf
        <button type="submit">Link Discord account</button>
    </form>
</body>
</html>
