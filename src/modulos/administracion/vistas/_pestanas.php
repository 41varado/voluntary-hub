<?php /** Pestañas de administración. Variable: $activa ('usuarios' | 'administradores') */ ?>
<nav class="pestanas" aria-label="Administración">
    <a href="<?= e(url('/admin/usuarios')) ?>"<?= $activa === 'usuarios' ? ' aria-current="page"' : '' ?>>Usuarios</a>
    <a href="<?= e(url('/admin/administradores')) ?>"<?= $activa === 'administradores' ? ' aria-current="page"' : '' ?>>Administradores</a>
</nav>
