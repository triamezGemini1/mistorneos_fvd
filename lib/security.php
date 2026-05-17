<?php


final class Security
{
    /**
     * Genera un hash seguro de contrase�a usando bcrypt
     */
    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    /**
     * Verifica una contrase�a contra su hash
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Autentica un usuario con username y password
     * Retorna array con datos del usuario o null si falla
     * Esta es la función centralizada de autenticación - TODOS los logins deben pasar por aquí
     */
    public static function authenticateUser(string $username, string $password): ?array
    {
        try {
            $pdo = DB::pdo();
            $usernameTrim = trim($username);

            $stmt = $pdo->prepare("
                SELECT id, username, password_hash, email, role, status, club_id, entidad, uuid, photo_path
                FROM usuarios
                WHERE username = ? OR email = ?
            ");
            $stmt->execute([$usernameTrim, $usernameTrim]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si no existe el usuario ($username es el valor enviado en esta petición, no de sesión)
            if (!$user) {
                error_log("Autenticación fallida (usuario enviado en petición: '{$username}'): no existe");
                return null;
            }

            // Verificar contraseña primero (no revelar si estaba inactivo si la contraseña falla)
            if (!self::verifyPassword($password, $user['password_hash'])) {
                error_log("Autenticación fallida (usuario enviado en petición: '{$username}'): contraseña incorrecta");
                return null;
            }

            // Auto-activar al iniciar sesión: si estaba inactivo (status != 0), activar sin más protocolos
            if ((int)$user['status'] !== 0) {
                try {
                    $up = DB::pdo()->prepare("UPDATE usuarios SET status = 0 WHERE id = ?");
                    $up->execute([$user['id']]);
                    $user['status'] = 0;
                    error_log("Usuario '{$username}' (id={$user['id']}) activado automáticamente al iniciar sesión");
                } catch (Exception $e) {
                    error_log("Error al activar usuario en login: " . $e->getMessage());
                }
            }

            // Bloqueo por is_active: si está en 0 (desactivado por Master Admin), no puede entrar (web)
            try {
                $stmt2 = DB::pdo()->prepare("SELECT is_active FROM usuarios WHERE id = ?");
                $stmt2->execute([$user['id']]);
                $row = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($row !== false && isset($row['is_active']) && (int)$row['is_active'] !== 1) {
                    error_log("Autenticación fallida (usuario enviado en petición: '{$username}'): desactivado is_active=0");
                    return null;
                }
            } catch (Throwable $e) {
                // Columna is_active puede no existir en instalaciones antiguas
            }

            // Usuario válido y autenticado
            return [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'uuid' => $user['uuid'] ?? null,
                'photo_path' => $user['photo_path'] ?? null,
                'club_id' => $user['club_id'] ? (int)$user['club_id'] : 0,
                'entidad' => isset($user['entidad']) ? (int)$user['entidad'] : 0
            ];
        } catch (Exception $e) {
            error_log("Error en autenticación: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Autentica un usuario admin_club espec�ficamente
     */
    public static function authenticateClubAdmin(string $username, string $password, string $expectedEmail = null): ?array
    {
        $user = self::authenticateUser($username, $password);
        
        if ($user && $user['role'] === 'admin_club') {
            // Si se especifica email, verificar que coincida
            if ($expectedEmail && $user['email'] !== $expectedEmail) {
                return null;
            }
            return $user;
        }
        
        return null;
    }

    /**
     * Genera username por defecto para clubes
     */
    public static function defaultClubUsername(int $clubId): string
    {
        return 'invitado' . $clubId;
    }

    /**
     * Contrase�a por defecto para clubes
     */
    public static function defaultClubPassword(): string
    {
        return 'invitado123';
    }

    /**
     * Crea un usuario de club con credenciales por defecto
     */
    public static function createClubUser(int $clubId, string $email, string $clubName = ''): array
    {
        $username = self::defaultClubUsername($clubId);
        $password = self::defaultClubPassword();
        $passwordHash = self::hashPassword($password);

        return [
            'username' => $username,
            'password' => $password,
            'password_hash' => $passwordHash,
            'email' => $email,
            'role' => 'admin_club',
            'status' => 0,
            'club_id' => $clubId,
            'must_change_password' => 1
        ];
    }

    /**
     * Función centralizada para crear usuarios
     * TODOS los lugares donde se crean usuarios deben usar esta función
     * para garantizar consistencia en validaciones, hash de contraseñas y campos
     * 
     * @param array $data Datos del usuario a crear
     * @return array ['success' => bool, 'user_id' => int|null, 'errors' => array]
     */
    public static function createUser(array $data): array
    {
        $errors = [];
        
        // Campos requeridos
        $required = ['username', 'password', 'role'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "El campo {$field} es requerido";
            }
        }
        
        // Validar username
        if (!empty($data['username'])) {
            $username = trim($data['username']);
            if (strlen($username) < 3) {
                $errors[] = 'El nombre de usuario debe tener al menos 3 caracteres';
            }
            if (!preg_match('/^[a-zA-Z0-9_\.]+$/', $username)) {
                $errors[] = 'El nombre de usuario solo puede contener letras, números, puntos y guiones bajos';
            }
        }
        
        // Validar password
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 6) {
                $errors[] = 'La contraseña debe tener al menos 6 caracteres';
            }
        }
        
        // Validar email si se proporciona
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'El email no es válido';
        }
        
        // Validar role
        $valid_roles = ['admin_general', 'admin_torneo', 'admin_club', 'usuario', 'operador'];
        if (!empty($data['role']) && !in_array($data['role'], $valid_roles)) {
            $errors[] = 'El rol seleccionado no es válido';
        }
        
        // Cédula: guardar solo el número (nunca concatenar nacionalidad con cédula)
        if (isset($data['cedula']) && $data['cedula'] !== '') {
            $data['cedula'] = preg_replace('/\D/', '', (string) $data['cedula']);
            if ($data['cedula'] === '') {
                $errors[] = 'La cédula debe contener al menos un dígito';
            }
        }
        // Nacionalidad: solo V, E, J, P (campo propio en usuarios)
        if (isset($data['nacionalidad']) && $data['nacionalidad'] !== '') {
            $nac = strtoupper(trim((string) $data['nacionalidad']));
            if (!in_array($nac, ['V', 'E', 'J', 'P'], true)) {
                $data['nacionalidad'] = 'V';
            } else {
                $data['nacionalidad'] = $nac;
            }
        }

        // Validar que admin_torneo y admin_club tengan club_id
        if (in_array($data['role'] ?? '', ['admin_torneo', 'admin_club']) && (empty($data['club_id']) || (int)$data['club_id'] <= 0)) {
            $errors[] = 'Los usuarios con rol ' . $data['role'] . ' deben tener un club asignado';
        }

        // Permitir club_id para rol usuario solo cuando se indica _allow_club_for_usuario (registro por club, torneo, etc.)
        $allow_club_usuario = !empty($data['_allow_club_for_usuario']);
        unset($data['_allow_club_for_usuario']);
        if ($allow_club_usuario && ($data['role'] ?? '') === 'usuario') {
            $club_id_val = (int) ($data['club_id'] ?? 0);
            $entidad_val = (int) ($data['entidad'] ?? 0);
            if ($club_id_val <= 0 && $entidad_val > 0) {
                require_once __DIR__ . '/AsociacionAdminHelper.php';
                $club_id_val = (int) (AsociacionAdminHelper::resolverClubIdDesdeEntidad(DB::pdo(), $entidad_val) ?? 0);
            }
            if ($club_id_val <= 0) {
                $errors[] = 'Debe seleccionar una asociación válida';
            } else {
                $data['club_id'] = $club_id_val;
                if ($entidad_val <= 0) {
                    $data['entidad'] = $club_id_val;
                }
            }
        } elseif (!$allow_club_usuario && in_array($data['role'] ?? '', ['admin_general', 'usuario']) && !empty($data['club_id'])) {
            $data['club_id'] = null; // Forzar a null
        }
        
        if (!empty($errors)) {
            return ['success' => false, 'user_id' => null, 'errors' => $errors];
        }
        
        try {
            $pdo = DB::pdo();
            
            // Verificar si el username ya existe
            $username = trim($data['username']);
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                return ['success' => false, 'user_id' => null, 'errors' => ['El nombre de usuario ya existe']];
            }
            
            // Verificar si la cédula ya existe (si se proporciona)
            if (!empty($data['cedula'])) {
                $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE cedula = ?");
                $stmt->execute([$data['cedula']]);
                if ($stmt->fetch()) {
                    return ['success' => false, 'user_id' => null, 'errors' => ['Ya existe un usuario con esta cédula']];
                }
            }
            
            // Generar UUID v4 (RFC 4122) si no se proporciona — para sincronización Offline-First
            $uuid = $data['uuid'] ?? null;
            if (empty($uuid)) {
                $uuid = self::uuidV4();
            }
            
            // Hash de la contraseña usando el método centralizado (nunca null: columna NOT NULL)
            $password_plain = isset($data['password']) && (string)$data['password'] !== '' ? $data['password'] : bin2hex(random_bytes(16));
            $password_hash = self::hashPassword($password_plain);

            // status: 0 = activo, 1 = inactivo (entero)
            $status = isset($data['status']) ? (in_array($data['status'], ['approved', 'active', 'activo', 0, '0'], true) ? 0 : 1) : 0;
            $fields = ['username', 'password_hash', 'role', 'status'];
            $values = [$username, $password_hash, $data['role'], $status];
            $placeholders = ['?', '?', '?', '?'];
            
            // Campos opcionales (nacionalidad existe en usuarios: V, E, J, P)
            $optional_fields = [
                'cedula' => 'cedula',
                'nacionalidad' => 'nacionalidad',
                'nombre' => 'nombre',
                'email' => 'email',
                'celular' => 'celular',
                'fechnac' => 'fechnac',
                'sexo' => 'sexo',
                'club_id' => 'club_id',
                'entidad' => 'entidad',
                'cod_org' => 'cod_org',
                'uuid' => 'uuid',
                'photo_path' => 'photo_path'
            ];
            
            foreach ($optional_fields as $key => $field) {
                if (isset($data[$key]) && $data[$key] !== null && $data[$key] !== '') {
                    $fields[] = $field;
                    $values[] = $data[$key];
                    $placeholders[] = '?';
                }
            }

            // Columnas NOT NULL sin valor: rellenar con valor por defecto (ej. registro Fast-Track)
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
                $existing = array_flip($fields);
                foreach ($cols as $col) {
                    $name = $col['Field'];
                    if (isset($existing[$name])) {
                        continue;
                    }
                    $null = strtoupper((string) ($col['Null'] ?? 'YES'));
                    $default = $col['Default'] ?? null;
                    $keyDefault = $col['Key'] ?? '';
                    if ($null === 'NO' && ($default === null || $default === '') && $keyDefault !== 'PRI' && $name !== 'id' && $name !== 'created_at') {
                        $type = strtoupper((string) ($col['Type'] ?? ''));
                        $defaultVal = (strpos($type, 'INT') !== false || strpos($type, 'DECIMAL') !== false) ? 0 : 'N/A';
                        $fields[] = $name;
                        $values[] = $defaultVal;
                        $placeholders[] = '?';
                    }
                }
            } catch (Throwable $e) {
                // Ignorar si la tabla no existe o no hay permiso
            }
            
            // Agregar created_at
            $fields[] = 'created_at';
            $values[] = date('Y-m-d H:i:s');
            $placeholders[] = '?';
            
            $sql = "INSERT INTO usuarios (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            
            $user_id = (int)$pdo->lastInsertId();
            
            return ['success' => true, 'user_id' => $user_id, 'errors' => []];
            
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            // Duplicate entry: 1062 (MySQL) o mensaje "Duplicate entry"
            if ($code == 23000 || $code == 1062 || stripos($msg, 'Duplicate entry') !== false) {
                if (stripos($msg, 'cedula') !== false) {
                    return ['success' => false, 'user_id' => null, 'errors' => ['Ya existe un usuario con esta cédula.']];
                }
                if (stripos($msg, 'username') !== false) {
                    return ['success' => false, 'user_id' => null, 'errors' => ['El nombre de usuario ya existe.']];
                }
                if (stripos($msg, 'email') !== false) {
                    return ['success' => false, 'user_id' => null, 'errors' => ['Ya existe un usuario con este correo electrónico.']];
                }
                return ['success' => false, 'user_id' => null, 'errors' => ['El registro ya existe (clave duplicada).']];
            }
            error_log("Error al crear usuario: " . $msg);
            return ['success' => false, 'user_id' => null, 'errors' => ['Error al crear el usuario: ' . $msg]];
        } catch (Exception $e) {
            error_log("Error al crear usuario: " . $e->getMessage());
            return ['success' => false, 'user_id' => null, 'errors' => ['Error al crear el usuario: ' . $e->getMessage()]];
        }
    }

    /**
     * Genera un UUID v4 (RFC 4122) para sincronización Offline-First
     */
    public static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}



