<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — DPI-RDC</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-xl shadow-lg p-8">
        <h1 class="text-2xl font-bold text-blue-900 text-center mb-2">DPI-RDC</h1>
        <p class="text-sm text-gray-600 text-center mb-8">{{ config('dpi.establishment_name') }}</p>

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf
            <div>
                <label for="login" class="block text-sm font-medium text-gray-700 mb-1">Email ou matricule</label>
                <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
                @error('login')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Mot de passe</label>
                <input id="password" name="password" type="password" required
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember" class="rounded">
                Se souvenir de moi
            </label>
            <button type="submit"
                class="w-full min-h-[44px] bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg transition">
                Se connecter
            </button>
        </form>
    </div>
</body>
</html>