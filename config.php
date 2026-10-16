<?php

return [
    // Exemplo Firebird remoto/local:
    // 127.0.0.1/3050:C:\\RESWINCS\\banco\\RESULTH.FB
    'database' => '127.0.0.1/3050:C:\\RESWINCS\\banco\\RESULTH.FB',
    'user'     => 'SYSDBA',
    'password' => 'masterkey',

    // UTF8 normalmente facilita a exibição no navegador.
    // Se o seu banco exigir outro charset, ajuste aqui.
    'charset'  => 'UTF8',

    // Empresa padrão do Resulth.
    'empresa'  => '00',
];
