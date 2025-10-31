<?php

namespace App\Http\Controllers\Api;

use App\Models\Komentar;
use App\Http\Resources\KomentarResource;
use Illuminate\Http\Request;

class KomentarController extends \App\Http\Controllers\Controller
{
    public function index(Request $request)
    {
        $q = $request->query('search');
        $per = (int)($request->query('per_page', 15));
        $data = Komentar::query()
            ->paginate($per);
        return KomentarResource::collection($data);
    }

    public function show($id)
    {
        $row = Komentar::findOrFail($id);
        return new KomentarResource($row);
    }

    public function store(Request $request)
    {
        $payload = $request->all();
        $row = Komentar::create($payload);
        return response()->json(new KomentarResource($row), 201);
    }

    public function update(Request $request, $id)
    {
        $row = Komentar::findOrFail($id);
        $row->fill($request->all())->save();
        return new KomentarResource($row);
    }

    public function destroy($id)
    {
        $row = Komentar::findOrFail($id);
        $row->delete();
        return response()->json(['deleted' => true]);
    }
}
