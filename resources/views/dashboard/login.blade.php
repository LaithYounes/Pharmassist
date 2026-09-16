<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Dashboard login</title></head>
<body>
    <h1>Dashboard login</h1>
    @if ($errors->any()) <p role="alert">{{ $errors->first() }}</p> @endif
    <form method="post" action="{{ route('login') }}">
        @csrf
        <label>Username <input name="username" autocomplete="username" required></label>
        <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
        <button type="submit">Log in</button>
    </form>
</body>
</html>
