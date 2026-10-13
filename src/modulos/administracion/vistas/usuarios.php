<?php
/** Variables: $resultado (items, total, pagina, paginas), $filtros */
$hayFiltros = $filtros['q'] !== '' || $filtros['rol'] !== '' || $filtros['estado'] !== '';
?>
<?= parcial('administracion/_pestanas', ['activa' => 'usuarios']) ?>

<div class="encabezado-seccion">
    <div>
        <h1>Usuarios</h1>
        <p class="texto-suave">Voluntarios y cuentas de coordinación de las organizaciones.</p>
    </div>
    <a class="boton" href="<?= e(url('/admin/usuarios/nuevo')) ?>">Crear usuario</a>
</div>

<form class="filtros" method="get" action="<?= e(url('/admin/usuarios')) ?>" role="search">
    <div class="campo">
        <label for="q">Nombre o correo</label>
        <input type="search" id="q" name="q" value="<?= e($filtros['q']) ?>" maxlength="60">
    </div>
    <div class="campo">
        <label for="rol">Tipo de cuenta</label>
        <select id="rol" name="rol">
            <option value="">Todas</option>
            <?php foreach (AdminUsuarios::ROLES_REGULARES as $rol): ?>
                <option value="<?= e($rol) ?>"<?= $filtros['rol'] === $rol ? ' selected' : '' ?>><?= e(Catalogos::NOMBRES_ROL[$rol]) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="campo">
        <label for="estado">Estado</label>
        <select id="estado" name="estado">
            <option value="">Activas y desactivadas</option>
            <option value="activos"<?= $filtros['estado'] === 'activos' ? ' selected' : '' ?>>Solo activas</option>
            <option value="desactivados"<?= $filtros['estado'] === 'desactivados' ? ' selected' : '' ?>>Solo desactivadas</option>
        </select>
    </div>
    <button class="boton boton--secundario" type="submit">Filtrar</button>
</form>

<div class="resumen-resultados" style="margin-top:1rem">
    <p><?= e(plural($resultado['total'], 'cuenta', 'cuentas')) ?>.
        <?php if ($hayFiltros): ?><a href="<?= e(url('/admin/usuarios')) ?>">Quitar filtros</a><?php endif; ?></p>
</div>

<?php if (!$resultado['items']): ?>
    <div class="vacio"><p>No hay cuentas con esos filtros.</p></div>
<?php else: ?>
    <div class="tabla-envoltura">
        <table class="tabla">
            <thead>
            <tr>
                <th scope="col">Nombre</th>
                <th scope="col">Correo</th>
                <th scope="col">Tipo</th>
                <th scope="col">Organización</th>
                <th scope="col">Estado</th>
                <th scope="col">Creada</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($resultado['items'] as $u): $activo = UsuarioRepositorio::estaActivo($u); ?>
                <tr>
                    <td><a href="<?= e(url('/admin/usuarios/' . $u['_id'])) ?>"><?= e($u['nombre']) ?></a></td>
                    <td><?= e($u['correo']) ?></td>
                    <td><?= e(Catalogos::NOMBRES_ROL[$u['rol']] ?? $u['rol']) ?></td>
                    <td><?= e($u['organizacion']['nombre'] ?? '') ?></td>
                    <td><span class="estado estado--<?= $activo ? 'ok' : 'caido' ?>"><?= $activo ? 'Activa' : 'Desactivada' ?></span></td>
                    <td><?= e(fecha_cr($u['creadoEn'], 'dia')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($resultado['paginas'] > 1): ?>
        <nav class="paginacion" aria-label="Páginas">
            <?php for ($p = 1; $p <= $resultado['paginas']; $p++): ?>
                <?php if ($p === $resultado['pagina']): ?>
                    <span aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= e(url('/admin/usuarios', $filtros + ['pagina' => $p])) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
