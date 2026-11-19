<?php
$credit='<footer class="text-center p-4">Software Development by <a href="https://www.energeticedge.lk/" target="_blank" rel="noopener noreferrer">Energetic Edge (Pvt) Ltd</a></footer><script src="'.e($base).'/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script><script src="'.e($base).'/assets/backend-navigation.js?v=61"></script>';
$html=str_replace('</head>','<link rel="stylesheet" href="'.e($base).'/assets/mobile-dashboard.css?v=1"><link rel="stylesheet" href="'.e($base).'/assets/backend-navigation.css?v=107"><link rel="stylesheet" href="'.e($base).'/assets/portion-options.css?v=4"></head>',$html);
$html=str_replace('</body>',$credit.'</body>',$html);
