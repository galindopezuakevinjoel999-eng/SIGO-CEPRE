<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Career;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CareerController extends Controller
{
    /**
     * Lista de carreras (público para formulario de matrícula).
     */
    public function index()
    {
        return response()->json(Career::active()->get());
    }

    public function store(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'unique:careers,code'],
            'name' => ['required', 'string'],
            'group' => ['required', Rule::in(array_keys(Career::GROUPS))],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        return response()->json(Career::create($data), 201);
    }

    public function show(Career $career)
    {
        return response()->json($career);
    }

    public function update(Request $request, Career $career)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:10', 'unique:careers,code,'.$career->id],
            'name' => ['nullable', 'string'],
            'group' => ['nullable', Rule::in(array_keys(Career::GROUPS))],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $career->update($data);
        return response()->json($career);
    }

    public function destroy(Career $career)
    {
        if (! request()->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $career->delete();
        return response()->json(['success' => true]);
    }
}
