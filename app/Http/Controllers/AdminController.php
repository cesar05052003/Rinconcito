<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Models\Pedido;
use PDF; // PDF generation library

class AdminController extends Controller
{
    public function index(Request $request)
    {
        // Obtener todos los usuarios sin filtrar por tipo para edición completa
        $clientes = User::where('tipo_usuario', 'cliente')->paginate(15);
        $chefs = User::where('tipo_usuario', 'chef')->paginate(15);
        $repartidores = User::where('tipo_usuario', 'repartidor')->paginate(15);

        // Obtener datos de reportes de ventas con paginación
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = Pedido::query();

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $pedidos = $query->with('plato', 'cliente')->paginate(15);

        foreach ($pedidos as $pedido) {
            $precio = $pedido->plato->precio ?? 0;
            $cantidad = $pedido->cantidad ?? 1;
            $pedido->totalCalculado = $precio * $cantidad;
        }

        $platos = \App\Models\Plato::all();

        $totalesPorPlato = $pedidos->groupBy('plato_id')->map(function ($pedidosPorPlato) {
            $cantidadTotal = $pedidosPorPlato->sum('cantidad');
            $totalValor = $pedidosPorPlato->sum(fn($pedido) => $pedido->totalCalculado ?? 0);
            $nombrePlato = $pedidosPorPlato->first()->plato->nombre ?? 'N/A';
            return [
                'nombre' => $nombrePlato,
                'cantidad_total' => $cantidadTotal,
                'total_valor' => $totalValor,
            ];
        });

        return view('admin', [
            'clientes' => $clientes,
            'chefs' => $chefs,
            'repartidores' => $repartidores,
            'pedidos' => $pedidos,
            'totalVentas' => $pedidos->sum(fn($pedido) => $pedido->totalCalculado ?? 0),
            'totalPedidos' => $pedidos->total(),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'platos' => $platos,
            'totalesPorPlato' => $totalesPorPlato,
        ]);
    }

    public function showChefDetails($id)
    {
        $chef = \App\Models\User::where('tipo_usuario', 'chef')->findOrFail($id);

        // Obtener platos del chef
        $platos = \App\Models\Plato::where('user_id', $chef->id)->get();

        // Obtener ventas por plato
        $ventasPorPlato = [];
        foreach ($platos as $plato) {
            $pedidos = \App\Models\Pedido::where('plato_id', $plato->id)->get();
            $cantidadVendida = $pedidos->sum('cantidad');
            $totalVentas = $pedidos->sum(function ($pedido) {
                return ($pedido->precio_con_descuento ?? 0) * $pedido->cantidad;
            });
            $ventasPorPlato[] = [
                'plato' => $plato,
                'cantidad_vendida' => $cantidadVendida,
                'total_ventas' => $totalVentas,
            ];
        }

        return view('admin_chef_details', [
            'chef' => $chef,
            'ventasPorPlato' => $ventasPorPlato,
        ]);
    }

    public function reportes(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = Pedido::query();

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $pedidos = $query->with('plato', 'cliente')->get();

        foreach ($pedidos as $pedido) {
            $precio = $pedido->plato->precio ?? 0;
            $cantidad = $pedido->cantidad ?? 1;
            $pedido->totalCalculado = $precio * $cantidad;
        }

        $platos = \App\Models\Plato::all();

        $totalesPorPlato = $pedidos->groupBy('plato_id')->map(function ($pedidosPorPlato) {
            $cantidadTotal = $pedidosPorPlato->sum('cantidad');
            $totalValor = $pedidosPorPlato->sum(fn($pedido) => $pedido->totalCalculado ?? 0);
            $nombrePlato = $pedidosPorPlato->first()->plato->nombre ?? 'N/A';
            return [
                'nombre' => $nombrePlato,
                'cantidad_total' => $cantidadTotal,
                'total_valor' => $totalValor,
            ];
        });

        return view('admin_reportes', [
            'pedidos' => $pedidos,
            'platos' => $platos,
            'totalesPorPlato' => $totalesPorPlato,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'usuarios' => User::where('tipo_usuario', 'cliente')->get(),
            'chefs' => User::where('tipo_usuario', 'chef')->get(),
            'repartidores' => User::where('tipo_usuario', 'repartidor')->get(),
            'todosUsuarios' => User::all(),
        ]);
    }

    public function indexPlatos()
    {
        $platos = \App\Models\Plato::all();
        return view('admin', [
            'platos' => $platos,
        ]);
    }

    public function destroyPlato($id)
    {
        $plato = \App\Models\Plato::findOrFail($id);
        $plato->delete();
        return redirect()->route('admin.platos.index')->with('success', 'Plato eliminado correctamente.');
    }

    public function create()
    {
        return view('admin_create_user');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'tipo_usuario' => ['required', Rule::in(['cliente', 'chef', 'repartidor', 'admin'])],
            'role' => 'nullable|string|max:255',
            'fecha_nacimiento' => 'nullable|date',
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'tipo_usuario' => $validated['tipo_usuario'],
            'role' => $validated['role'] ?? null,
            'fecha_nacimiento' => $validated['fecha_nacimiento'] ?? null,
        ]);

        return redirect()->route('admin.dashboard')->with('success', 'Usuario creado correctamente.');
    }

    public function edit(User $user)
    {
        return view('admin_edit_user', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required','email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'tipo_usuario' => ['required', Rule::in(['cliente', 'chef', 'repartidor', 'admin'])],
            'role' => 'nullable|string|max:255',
            'fecha_nacimiento' => 'nullable|date',
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        $user->tipo_usuario = $validated['tipo_usuario'];
        $user->role = $validated['role'] ?? null;
        $user->fecha_nacimiento = $validated['fecha_nacimiento'] ?? null;
        $user->save();

        return redirect()->route('admin.dashboard')->with('success', 'Usuario actualizado correctamente.');
    }

    public function destroy(User $user)
    {
        $user->delete();
        return redirect()->route('admin.dashboard')->with('success', 'Usuario eliminado correctamente.');
    }

    public function reportePdf(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = Pedido::query();

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $pedidos = $query->with('plato', 'cliente')->get();

        foreach ($pedidos as $pedido) {
            $precio = $pedido->plato->precio ?? 0;
            $cantidad = $pedido->cantidad ?? 1;
            $pedido->totalCalculado = $precio * $cantidad;
        }

        $totalesPorPlato = $pedidos->groupBy('plato_id')->map(function ($pedidosPorPlato) {
            $cantidadTotal = $pedidosPorPlato->sum('cantidad');
            $totalValor = $pedidosPorPlato->sum(function($pedido) {
                return $pedido->totalCalculado ?? 0;
            });
            $nombrePlato = $pedidosPorPlato->first()->plato->nombre ?? 'N/A';
            return [
                'nombre' => $nombrePlato,
                'cantidad_total' => $cantidadTotal,
                'total_valor' => $totalValor,
            ];
        });

        $totalPedidos = $pedidos->count();
        $totalVentas = $pedidos->sum(fn($pedido) => $pedido->totalCalculado ?? 0);
        $generatedAt = now()->format('d/m/Y H:i');

        $pdf = PDF::loadView('reports.admin_pdf', [
            'pedidos' => $pedidos,
            'totalesPorPlato' => $totalesPorPlato,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'totalPedidos' => $totalPedidos,
            'totalVentas' => $totalVentas,
            'generatedAt' => $generatedAt,
        ]);

        return $pdf->download('reporte_ventas.pdf');
    }
}
