<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * Mostrar el perfil del usuario autenticado con porcentaje de completitud.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $user->append('profile_percentage');

        return response()->json($user);
    }

    /**
     * Actualizar datos personales.
     * El DNI no se puede editar una vez completado.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $rules = [
            'name' => ['required', 'string', 'min:2'],
            'phone' => ['nullable', 'string'],
            'photo_url' => ['nullable', 'string'],
        ];

        // DNI: solo se puede establecer si está vacío (primera vez)
        if (empty($user->dni)) {
            $rules['dni'] = ['nullable', 'string', Rule::unique('users')->ignore($user->id)];
        }
        // Si ya tiene DNI, no permitir cambiarlo
        // (si intentan enviarlo, simplemente lo ignoramos)

        $data = $request->validate($rules);

        // Si ya tiene DNI, remover del update
        if (! empty($user->dni)) {
            unset($data['dni']);
        }

        $user->fill($data);
        // El perfil está completo cuando tiene DNI y teléfono (RF09). Habilita la
        // matrícula y los pagos (middleware profile.completed).
        $user->profile_completed = filled($user->dni) && filled($user->phone);
        $user->save();

        $user->append('profile_percentage');

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }
}
