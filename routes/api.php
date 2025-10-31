<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

use App\Http\Controllers\Api\{
    FilmController,
    GenreController,
    JadwalController,
    TiketController,
    TransaksiController,
    KasirController,
    CustomerController,
    DetailTransaksiController,
    KomentarController,
    KursiController,
    StudioController,
    UserController
};

/* ---------- helpers kecil ---------- */
function table_has_col(string $table, string $col): bool {
    try { return Schema::hasColumn($table, $col); } catch (\Throwable $e) { return false; }
}
function pick_auth_table(): ?string {
    if (Schema::hasTable('users'))    return 'users';
    if (Schema::hasTable('customer')) return 'customer';
    if (Schema::hasTable('pelanggan'))return 'pelanggan';
    return null;
}
/** Cari jadwal kanonik untuk slot (studio+tanggal+jam_mulai) */
function canonical_jadwal(int $jadwalId): array {
    $jd = DB::table('jadwal')->where('jadwal_id',$jadwalId)->first();
    if (!$jd) return [null, [], null];
    $ids = DB::table('jadwal')
        ->where('studio_id', $jd->studio_id)
        ->where('tanggal',   $jd->tanggal)
        ->where('jam_mulai', $jd->jam_mulai)
        ->orderBy('jadwal_id')
        ->pluck('jadwal_id')->all();
    if (empty($ids)) return [null, [], null];
    $canonId = (int)min($ids);
    return [$canonId, $ids, $jd];
}

/* ---------- root & ping ---------- */
Route::get('/',     fn() => response()->json(['message' => 'API Bioskop Laravel aktif 🚀']));
Route::get('/ping', fn() => response()->json(['pong' => now()->toIso8601String()]));

/* ---------- AUTH ---------- */
Route::post('/auth/register', function (Request $r) {
    $table = pick_auth_table();
    if (!$table) return response()->json(['message'=>'Tabel users/customer tidak ditemukan'], 500);
    $username = trim((string)$r->input('username'));
    $password = (string)$r->input('password');
    $name     = $r->input('name');
    $email    = $r->input('email');
    if ($username === '' || $password === '') {
        return response()->json(['message' => 'username & password wajib'], 422);
    }
    if (table_has_col($table,'username') && DB::table($table)->where('username',$username)->exists()) {
        return response()->json(['message'=>'Username sudah terpakai'], 409);
    }
    if ($email && table_has_col($table,'email') && DB::table($table)->where('email',$email)->exists()) {
        return response()->json(['message'=>'Email sudah terpakai'], 409);
    }
    $displayName = $name ?: $username ?: (is_string($email) ? explode('@', $email)[0] : null);
    $insert = [];
    if (table_has_col($table,'username')) $insert['username'] = $username;
    if (table_has_col($table,'password')) $insert['password'] = Hash::make($password);
    if (table_has_col($table,'name') && $displayName) $insert['name'] = $displayName;
    if (table_has_col($table,'email') && $email) $insert['email'] = $email;
    if (table_has_col($table,'api_token')) $insert['api_token'] = Str::random(40);
    if (table_has_col($table,'created_at')) $insert['created_at'] = now();
    if (table_has_col($table,'updated_at')) $insert['updated_at'] = now();
    $id = DB::table($table)->insertGetId($insert);
    return response()->json(['ok'=>true,'message'=>'Registrasi berhasil','id'=>$id,'table'=>$table], 201);
});

Route::post('/auth/login', function (Request $r) {
    $table = pick_auth_table();
    if (!$table) return response()->json(['message'=>'Tabel users/customer tidak ditemukan'], 500);
    $idOrName = trim((string)$r->input('username'));
    $password = (string)$r->input('password');
    if ($idOrName === '' || $password === '') {
        return response()->json(['message' => 'Username & password wajib'], 422);
    }
    $cols = [];
    if (table_has_col($table,'username')) $cols[] = 'username';
    if (table_has_col($table,'email'))    $cols[] = 'email';
    if (table_has_col($table,'name'))     $cols[] = 'name';

    $user = DB::table($table)->where(function ($q) use ($cols, $idOrName) {
        foreach ($cols as $c) $q->orWhere($c, $idOrName);
    })->first();

    if (!$user || (table_has_col($table,'password') && !Hash::check($password, $user->password))) {
        return response()->json(['message' => 'Username atau password salah'], 401);
    }
    $token = Str::random(40);
    if (table_has_col($table,'api_token')) {
        DB::table($table)->where('id', $user->id)->update(['api_token' => $token, 'updated_at' => now()]);
    }
    return response()->json(['ok'=>true,'token'=>$token,'user'=>$user], 200);
});
Route::post('/auth/logout', fn() => response()->json(['ok'=>true]));

/* ---------- GENRES (fallback) ---------- */
function _pick_genre_table() {
    foreach (['genres', 'genre', 'tbl_genre'] as $t) if (Schema::hasTable($t)) return $t;
    return null;
}
function _genre_id_col($t) {
    foreach (['genre_id', 'id', 'id_genre'] as $c) if (Schema::hasColumn($t, $c)) return $c;
    return 'id';
}
function _genre_name_col($t) {
    foreach (['nama', 'nama_genre', 'name', 'judul', 'title'] as $c) if (Schema::hasColumn($t, $c)) return $c;
    return 'nama';
}
Route::get('/genres', function () {
    $t = _pick_genre_table();
    if (!$t) return response()->json(['message' => 'Tabel genre tidak ditemukan'], 500);
    $id  = _genre_id_col($t);
    $nam = _genre_name_col($t);
    $rows = DB::table($t)->select([$id.' as id', $nam.' as nama'])->orderBy($id)->get();
    return response()->json($rows);
});
Route::get('/genres/{id}', function ($id) {
    $t = _pick_genre_table();
    if (!$t) return response()->json(['message' => 'Tabel genre tidak ditemukan'], 500);
    $idCol  = _genre_id_col($t);
    $namCol = _genre_name_col($t);
    $row = DB::table($t)->where($idCol, $id)->select([$idCol.' as id', $namCol.' as nama'])->first();
    if (!$row) return response()->json(['message' => 'Genre tidak ditemukan'], 404);
    return response()->json($row);
});

/* ---------- FILM ---------- */
Route::get('/film',         [FilmController::class, 'index']);
Route::post('/film',        [FilmController::class, 'store']);
Route::get('/film/{id}',    [FilmController::class, 'show']);
Route::put('/film/{id}',    [FilmController::class, 'update']);
Route::delete('/film/{id}', [FilmController::class, 'destroy']);

Route::get('/films',         [FilmController::class, 'index']);
Route::post('/films',        [FilmController::class, 'store']);
Route::get('/films/{id}',    [FilmController::class, 'show']);
Route::put('/films/{id}',    [FilmController::class, 'update']);
Route::delete('/films/{id}', [FilmController::class, 'destroy']);

/* ---------- JADWAL & KURSI ---------- */
Route::get('/jadwal',                         [JadwalController::class, 'index']);
Route::post('/jadwal',                        [JadwalController::class, 'store']);
Route::get('/jadwal/by-film/{film}',          [JadwalController::class, 'getByFilm']);
Route::get('/jadwal/film/{film}',             [JadwalController::class, 'getByFilm']); // alias
Route::get('/jadwal/{jadwal}/seats',          [JadwalController::class, 'getSeats']);
Route::get('/jadwal/{jadwal}/kursi-tersedia', [JadwalController::class, 'getSeats']);  // alias

// SHOW (tetap)
Route::get('/jadwal/{id}', function ($id) {
    $row = DB::table('jadwal as j')
        ->leftJoin('film as f',   'f.film_id',   '=', 'j.film_id')
        ->leftJoin('studio as s', 's.studio_id', '=', 'j.studio_id')
        ->where('j.jadwal_id', (int)$id)
        ->select([
            'j.jadwal_id','j.film_id','j.studio_id','j.tanggal','j.jam_mulai','j.jam_selesai',
            DB::raw('f.judul as film_judul'),
            DB::raw('s.nama_studio as nama_studio'),
        ])->first();

    if (!$row) return response()->json(['message'=>'Jadwal tidak ditemukan'], 404);
    return response()->json($row);
})->whereNumber('id');

// UPDATE (tetap)
Route::put('/jadwal/{id}', function (Request $r, $id) {
    $id = (int)$id;
    if (!DB::table('jadwal')->where('jadwal_id',$id)->exists()) {
        return response()->json(['message'=>'Jadwal tidak ditemukan'], 404);
    }

    $r->validate([
        'film_id'     => 'required|integer',
        'studio_id'   => 'required|integer',
        'tanggal'     => 'required|date',
        'jam_mulai'   => 'required|date_format:H:i:s',
        'jam_selesai' => 'required|date_format:H:i:s',
    ]);

    $upd = $r->only(['film_id','studio_id','tanggal','jam_mulai','jam_selesai']);
    if (table_has_col('jadwal','updated_at')) $upd['updated_at'] = now();

    DB::table('jadwal')->where('jadwal_id',$id)->update($upd);

    $row = DB::table('jadwal as j')
        ->leftJoin('film as f',   'f.film_id',   '=', 'j.film_id')
        ->leftJoin('studio as s', 's.studio_id', '=', 'j.studio_id')
        ->where('j.jadwal_id',$id)
        ->select([
            'j.jadwal_id','j.film_id','j.studio_id','j.tanggal','j.jam_mulai','j.jam_selesai',
            DB::raw('f.judul as film_judul'),
            DB::raw('s.nama_studio as nama_studio'),
        ])->first();

    return response()->json($row);
})->whereNumber('id');

// DELETE (mendukung sinkron slot)
Route::delete('/jadwal/{id}', [JadwalController::class, 'destroy'])->whereNumber('id');

/* ---------- CHECKOUT (pakai jadwal KANONIK) ---------- */
Route::post('/checkout', function (Request $r) {
    $r->validate([
        'customer_id' => 'required|integer',
        'jadwal_id'   => 'required|integer',
        'kursi_ids'   => 'required|array|min:1',
        'kursi_ids.*' => 'integer',
        'kasir_id'    => 'nullable|integer',
    ]);

    $customerId = (int)$r->input('customer_id');
    $jadwalId   = (int)$r->input('jadwal_id');
    $ids        = $r->input('kursi_ids');
    $kasirId    = $r->input('kasir_id');

    [$canonId, $_group, $jd] = canonical_jadwal($jadwalId);
    if (!$canonId || !$jd) return response()->json(['message'=>'Jadwal tidak ditemukan'], 404);

    $studioId = (int)$jd->studio_id;
    $filmId   = (int)($jd->film_id ?? 0);

    $withTimestamps = function (string $table, array $data) {
        if (Schema::hasColumn($table, 'created_at')) $data['created_at'] = now();
        if (Schema::hasColumn($table, 'updated_at')) $data['updated_at'] = now();
        return $data;
    };

    $defaultPrice = (function(int $studio) {
        $map = [1 => 50000, 2 => 100000, 3 => 75000];
        return $map[$studio] ?? 50000;
    })($studioId);

    $prefix = (function(int $studioId) {
        $base = ord('A') + max(0, $studioId - 1);
        return chr(min($base, ord('Z')));
    })($studioId);

    $ensureKursiId = function(int $id) use ($studioId, $prefix) {
        if ($id >= 0) return $id; // sudah ID asli kursi
        $n       = -$id;
        $sFromId = intdiv($n, 1000);
        $nomor   = $n % 1000;
        if ($sFromId !== $studioId || $nomor <= 0) {
            abort(response()->json(['message' => 'Kursi tidak valid untuk studio ini'], 422));
        }
        $label = $prefix.$nomor;

        $exist = DB::table('kursi')
            ->where('studio_id', $studioId)
            ->where('nomor_kursi', $label)
            ->first();

        if ($exist) return (int)$exist->kursi_id;

        return (int)DB::table('kursi')->insertGetId([
            'nomor_kursi' => $label,
            'studio_id'   => $studioId,
        ]);
    };

    return DB::transaction(function () use (
        $ids, $customerId, $canonId, $kasirId, $defaultPrice, $withTimestamps, $ensureKursiId, $filmId
    ) {
        $kursiIds = array_map($ensureKursiId, $ids);

        $tiketRows = [];
        foreach ($kursiIds as $kid) {
            $t = DB::table('tiket')
                ->where('jadwal_id', $canonId)
                ->where('kursi_id',  $kid)
                ->lockForUpdate()
                ->first();

            if (!$t) {
                $ins = [
                    'jadwal_id' => $canonId,
                    'kursi_id'  => $kid,
                    'harga'     => $defaultPrice,
                    'status'    => 'tersedia',
                ];
                $ins   = $withTimestamps('tiket', $ins);
                $tid   = DB::table('tiket')->insertGetId($ins);
                $t     = DB::table('tiket')->where('tiket_id', $tid)->first();
            }

            // Cegah double sell
            $status = strtolower((string)($t->status ?? 'tersedia'));
            if (in_array($status, ['sold','terjual'])) {
                abort(response()->json(['message' => "Kursi $kid sudah terjual"], 409));
            }

            // Harga anti 0
            if ((float)($t->harga ?? 0) <= 0) {
                $upd = ['harga' => $defaultPrice];
                if (Schema::hasColumn('tiket','updated_at')) $upd['updated_at'] = now();
                DB::table('tiket')->where('tiket_id', $t->tiket_id)->update($upd);
                $t->harga = $defaultPrice;
            }

            $tiketRows[] = $t;
        }

        $total = array_sum(array_map(fn($t) => (int)round((float)($t->harga ?? 0)), $tiketRows));

        $trx = [
            'customer_id'       => $customerId,
            'kasir_id'          => $kasirId,
            'tanggal_transaksi' => now(),
            'total_harga'       => $total,
        ];
        $trx   = $withTimestamps('transaksi', $trx);
        $trxId = DB::table('transaksi')->insertGetId($trx);

        foreach ($tiketRows as $t) {
            $detail = [
                'transaksi_id' => $trxId,
                'tiket_id'     => $t->tiket_id,
                'harga'        => $t->harga,
            ];
            if (Schema::hasColumn('detail_transaksi', 'film_id')) {
                $detail['film_id'] = $filmId;
            }
            $detail = $withTimestamps('detail_transaksi', $detail);
            DB::table('detail_transaksi')->insert($detail);

            $upd = ['status' => 'terjual'];
            if (Schema::hasColumn('tiket','updated_at')) $upd['updated_at'] = now();
            DB::table('tiket')->where('tiket_id', $t->tiket_id)->update($upd);
        }

        // Label kursi
        $kursiIdList = array_map(fn($t) => (int)$t->kursi_id, $tiketRows);
        $kursiMap = DB::table('kursi')
            ->whereIn('kursi_id', $kursiIdList)
            ->pluck('nomor_kursi', 'kursi_id');

        $kursiDetail = array_map(function($t) use ($kursiMap) {
            $kid = (int)$t->kursi_id;
            return [
                'tiket_id'    => (int)$t->tiket_id,
                'kursi_id'    => $kid,
                'nomor_kursi' => (string)($kursiMap[$kid] ?? ('S'.$kid)),
                'harga'       => (int)round((float)($t->harga ?? 0)),
            ];
        }, $tiketRows);

        return response()->json([
            'ok'            => true,
            'transaksi_id'  => $trxId,
            'total_harga'   => (int)$total,
            'kursi_terbeli' => $kursiIdList,
            'kursi'         => $kursiDetail,
            'kursi_labels'  => implode(', ', array_map(fn($d)=>$d['nomor_kursi'], $kursiDetail)),
        ]);
    });
});

/* ---------- ekstensi opsional ---------- */
$append = __DIR__ . '/api.append.php';
if (file_exists($append)) { require $append; }
