<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    protected string $table = 'users';

    protected function pk(): string
    {
        // Deteksi nama primary key yang umum
        if (Schema::hasColumn($this->table, 'id')) return 'id';
        if (Schema::hasColumn($this->table, 'user_id')) return 'user_id';
        return 'id';
    }

    protected function hasCol(string $col): bool
    {
        try { return Schema::hasColumn($this->table, $col); }
        catch (\Throwable $e) { return false; }
    }

    /**
     * GET /api/users?search=&per_page=
     */
    public function index(Request $r)
    {
        $perPage = (int) ($r->query('per_page', 15));
        $perPage = $perPage > 0 ? $perPage : 15;

        $q = DB::table($this->table);

        if ($search = $r->query('search')) {
            $q->when($this->hasCol('username'), fn($qq) => $qq->orWhere('username', 'like', "%$search%"))
              ->when($this->hasCol('email'),    fn($qq) => $qq->orWhere('email', 'like', "%$search%"))
              ->when($this->hasCol('name'),     fn($qq) => $qq->orWhere('name', 'like', "%$search%"))
              ->when($this->hasCol('nama'),     fn($qq) => $qq->orWhere('nama', 'like', "%$search%"));
        }

        return $q->orderBy($this->pk(), 'desc')->paginate($perPage);
    }

    /**
     * GET /api/users/{id}
     */
    public function show($id)
    {
        $row = DB::table($this->table)->where($this->pk(), $id)->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        return response()->json($row);
    }

    /**
     * POST /api/users
     * body: { username(required), password(required), email(optional), name(optional) }
     */
    public function store(Request $r)
    {
        // Wajib minimal username + password (email opsional)
        $rules = [
            'username' => ['required', 'string', Rule::unique($this->table, 'username')],
            'password' => ['required', 'string', 'min:4'],
            'email'    => ['nullable', 'email', Rule::unique($this->table, 'email')],
            'name'     => ['nullable', 'string'],
        ];
        $r->validate($rules);

        $data = [];
        if ($this->hasCol('username')) $data['username'] = $r->input('username');
        if ($this->hasCol('email')   && $r->filled('email')) $data['email'] = $r->input('email');
        if ($this->hasCol('name')    && $r->filled('name'))  $data['name']  = $r->input('name');
        if ($this->hasCol('nama')    && $r->filled('name'))  $data['nama']  = $r->input('name');

        if ($this->hasCol('password')) $data['password'] = Hash::make($r->input('password'));
        if ($this->hasCol('created_at')) $data['created_at'] = now();
        if ($this->hasCol('updated_at')) $data['updated_at'] = now();

        $id = DB::table($this->table)->insertGetId($data);
        $row = DB::table($this->table)->where($this->pk(), $id)->first();

        return response()->json($row, 201);
    }

    /**
     * PUT /api/users/{id}
     */
    public function update(Request $r, $id)
    {
        $rules = [
            'username' => ['nullable', 'string', Rule::unique($this->table, 'username')->ignore($id, $this->pk())],
            'email'    => ['nullable', 'email', Rule::unique($this->table, 'email')->ignore($id, $this->pk())],
            'name'     => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:4'],
        ];
        $r->validate($rules);

        $data = [];
        if ($this->hasCol('username') && $r->filled('username')) $data['username'] = $r->input('username');
        if ($this->hasCol('email')    && $r->filled('email'))    $data['email']    = $r->input('email');
        if ($this->hasCol('name')     && $r->filled('name'))     $data['name']     = $r->input('name');
        if ($this->hasCol('nama')     && $r->filled('name'))     $data['nama']     = $r->input('name');
        if ($this->hasCol('password') && $r->filled('password')) $data['password'] = Hash::make($r->input('password'));
        if ($this->hasCol('updated_at')) $data['updated_at'] = now();

        if ($data) {
            DB::table($this->table)->where($this->pk(), $id)->update($data);
        }

        $row = DB::table($this->table)->where($this->pk(), $id)->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        return response()->json($row);
    }

    /**
     * DELETE /api/users/{id}
     */
    public function destroy($id)
    {
        $deleted = DB::table($this->table)->where($this->pk(), $id)->delete();
        return response()->json(['deleted' => (bool) $deleted]);
    }
}
