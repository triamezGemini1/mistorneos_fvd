<?php



namespace Lib\Database;

use PDO;

/**
 * Repository Base - Patr�n Repository para abstracción de acceso a datos
 * 
 * Caracter�sticas:
 * - Abstracción completa de la capa de datos
 * - CRUD operations gen�ricos
 * - Query Builder integration
 * - Cache integration ready
 * - Audit logging ready
 * 
 * @package Lib\Database
 * @version 1.0.0
 */
abstract class Repository
{
    protected PDO $pdo;
    protected QueryBuilder $query;
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $hidden = [];
    protected array $casts = [];

    /**
     * Constructor
     * 
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->query = new QueryBuilder($pdo);
    }

    /**
     * Obtiene query builder para consultas custom
     * 
     * @return QueryBuilder
     */
    public function query(): QueryBuilder
    {
        return (new QueryBuilder($this->pdo))->table($this->table);
    }

    /**
     * Encuentra registro por ID
     * 
     * @param int|string $id
     * @return array|null
     */
    public function find($id): ?array
    {
        $result = $this->query()
            ->where($this->primaryKey, '=', $id)
            ->first();
        
        return $result ? $this->cast($this->hideFields($result)) : null;
    }

    /**
     * Encuentra registro o lanza excepción
     * 
     * @param int|string $id
     * @return array
     * @throws \RuntimeException
     */
    public function findOrFail($id): array
    {
        $result = $this->find($id);
        
        if ($result === null) {
            throw new \RuntimeException("Record not found in {$this->table} with {$this->primaryKey} = {$id}");
        }
        
        return $result;
    }

    /**
     * Obtiene todos los registros
     * 
     * @param array $columns Columnas a seleccionar
     * @return array
     */
    public function all(array $columns = ['*']): array
    {
        $results = $this->query()
            ->select($columns)
            ->get();
        
        return array_map(
            fn($record) => $this->cast($this->hideFields($record)),
            $results
        );
    }

    /**
     * Crea nuevo registro
     * 
     * @param array $data
     * @return array Registro creado con ID
     */
    public function create(array $data): array
    {
        $fillableData = $this->filterFillable($data);
        
        $id = $this->query()->table($this->table)->insert($fillableData);
        
        return $this->find($id) ?? $fillableData;
    }

    /**
     * Actualiza registro
     * 
     * @param int|string $id
     * @param array $data
     * @return bool
     */
    public function update($id, array $data): bool
    {
        $fillableData = $this->filterFillable($data);
        
        $affected = $this->query()
            ->where($this->primaryKey, '=', $id)
            ->update($fillableData);
        
        return $affected > 0;
    }

    /**
     * Elimina registro
     * 
     * @param int|string $id
     * @return bool
     */
    public function delete($id): bool
    {
        $affected = $this->query()
            ->where($this->primaryKey, '=', $id)
            ->delete();
        
        return $affected > 0;
    }

    /**
     * Encuentra registros donde columna = valor
     * 
     * @param string $column
     * @param mixed $value
     * @return array
     */
    public function findBy(string $column, $value): array
    {
        $results = $this->query()
            ->where($column, '=', $value)
            ->get();
        
        return array_map(
            fn($record) => $this->cast($this->hideFields($record)),
            $results
        );
    }

    /**
     * Encuentra primer registro donde columna = valor
     * 
     * @param string $column
     * @param mixed $value
     * @return array|null
     */
    public function findOneBy(string $column, $value): ?array
    {
        $result = $this->query()
            ->where($column, '=', $value)
            ->first();
        
        return $result ? $this->cast($this->hideFields($result)) : null;
    }

    /**
     * Verifica si existe registro
     * 
     * @param int|string $id
     * @return bool
     */
    public function exists($id): bool
    {
        $count = $this->query()
            ->where($this->primaryKey, '=', $id)
            ->count();
        
        return $count > 0;
    }

    /**
     * Cuenta todos los registros
     * 
     * @return int
     */
    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * Paginación
     * 
     * @param int $page Página actual (1-based)
     * @param int $perPage Items por página
     * @return array ['data' => [...], 'pagination' => [...]]
     */
    public function paginate(int $page = 1, int $perPage = 15): array
    {
        $total = $this->count();
        $totalPages = (int)ceil($total / $perPage);
        
        $data = $this->query()
            ->paginate($page, $perPage)
            ->get();
        
        $data = array_map(
            fn($record) => $this->cast($this->hideFields($record)),
            $data
        );
        
        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'from' => (($page - 1) * $perPage) + 1,
                'to' => min($page * $perPage, $total),
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages
            ]
        ];
    }

    /**
     * Filtra solo campos fillable
     * 
     * @param array $data
     * @return array
     */
    protected function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data;
        }
        
        return array_intersect_key(
            $data,
            array_flip($this->fillable)
        );
    }

    /**
     * Oculta campos sensibles
     * 
     * @param array $record
     * @return array
     */
    protected function hideFields(array $record): array
    {
        foreach ($this->hidden as $field) {
            unset($record[$field]);
        }
        
        return $record;
    }

    /**
     * Castea campos según definición
     * 
     * @param array $record
     * @return array
     */
    protected function cast(array $record): array
    {
        foreach ($this->casts as $field => $type) {
            if (!isset($record[$field])) {
                continue;
            }
            
            switch ($type) {
                case 'int':
                case 'integer':
                    $record[$field] = (int)$record[$field];
                    break;
                case 'float':
                case 'double':
                    $record[$field] = (float)$record[$field];
                    break;
                case 'bool':
                case 'boolean':
                    $record[$field] = (bool)$record[$field];
                    break;
                case 'string':
                    $record[$field] = (string)$record[$field];
                    break;
                case 'array':
                case 'json':
                    $record[$field] = is_string($record[$field]) ? json_decode($record[$field], true) : $record[$field];
                    break;
                case 'datetime':
                    $record[$field] = $record[$field] ? new \DateTime($record[$field]) : null;
                    break;
            }
        }
        
        return $record;
    }

    /**
     * Ejecuta en transacción
     * 
     * @param callable $callback
     * @return mixed
     * @throws \Throwable
     */
    public function transaction(callable $callback)
    {
        $this->pdo->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Bulk insert (mass insert)
     * 
     * @param array $records Array de arrays con datos
     * @return int Número de registros insertados
     */
    public function bulkInsert(array $records): int
    {
        if (empty($records)) {
            return 0;
        }
        
        return $this->transaction(function() use ($records) {
            $count = 0;
            
            foreach ($records as $record) {
                $this->create($record);
                $count++;
            }
            
            return $count;
        });
    }

    /**
     * Actualiza o crea registro
     * 
     * @param array $attributes Condiciones para buscar
     * @param array $values Valores a actualizar/crear
     * @return array
     */
    public function updateOrCreate(array $attributes, array $values = []): array
    {
        $record = null;
        
        $query = $this->query();
        foreach ($attributes as $column => $value) {
            $query->where($column, '=', $value);
        }
        
        $record = $query->first();
        
        if ($record) {
            $this->update($record[$this->primaryKey], $values);
            return $this->find($record[$this->primaryKey]);
        }
        
        return $this->create(array_merge($attributes, $values));
    }

    /**
     * Soft delete (si la tabla tiene deleted_at)
     * 
     * @param int|string $id
     * @return bool
     */
    public function softDelete($id): bool
    {
        return $this->update($id, [
            'deleted_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Restaura soft deleted record
     * 
     * @param int|string $id
     * @return bool
     */
    public function restore($id): bool
    {
        return $this->update($id, [
            'deleted_at' => null
        ]);
    }

    /**
     * Raw query con prepared statements
     * 
     * @param string $sql
     * @param array $bindings
     * @return array
     */
    protected function raw(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($bindings as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $placeholder = is_int($key) ? $key + 1 : $key;
            $stmt->bindValue($placeholder, $value, $type);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}







