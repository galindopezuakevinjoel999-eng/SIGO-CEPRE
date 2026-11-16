<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(User::orderBy('created_at', 'desc')->paginate(20));
    }

    public function store(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_ESTUDIANTE, User::ROLE_DOCENTE, User::ROLE_COORDINADOR])],
            'dni' => ['nullable', 'string', Rule::unique('users', 'dni')],
            'phone' => ['nullable', 'string'],
            'photo_url' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $data['password'] = Hash::make($data['password']);
        $data['profile_completed'] = ! empty($data['dni']) && ! empty($data['phone']) && ! empty($data['name']);

        $user = User::create($data);

        return response()->json($user, 201);
    }

    public function show(Request $request, User $user)
    {
        if (! $request->user()->isAdmin() && $request->user()->id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($user);
    }

    public function update(Request $request, User $user)
    {
        if (! $request->user()->isAdmin() && $request->user()->id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rules = [
            'name' => ['nullable', 'string', 'min:2'],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'dni' => ['nullable', 'string', Rule::unique('users', 'dni')->ignore($user->id)],
            'phone' => ['nullable', 'string'],
            'photo_url' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];

        if ($request->user()->isAdmin()) {
            $rules['role'] = ['nullable', Rule::in([User::ROLE_ADMIN, User::ROLE_ESTUDIANTE, User::ROLE_DOCENTE, User::ROLE_COORDINADOR])];
        }

        $data = $request->validate($rules);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if (! empty($data['role']) && ! $request->user()->isAdmin()) {
            unset($data['role']);
        }

        if (! empty($data['dni']) && $user->dni) {
            $data['dni'] = $user->dni;
        }

        $user->update($data);

        return response()->json($user);
    }

    public function destroy(Request $request, User $user)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta'], 422);
        }

        $user->delete();

        return response()->json(['success' => true]);
    }
}
