<?php
declare(strict_types=1);
function mapBoardSettingsDefaults():array {
    return ['width'=>1400,'height'=>1000,'cellSize'=>70,'cellSizeY'=>null,'folder'=>'',
        'boardColor'=>'#252d2b','backgroundColor'=>'#eee9da','dominantColor'=>'#eee9da','autoDominant'=>true,'backgroundMode'=>'image','cropOutside'=>true,
        'gridVisible'=>true,'gridType'=>'square','gridColor'=>'#000000','gridOpacity'=>35,'offsetX'=>0,'offsetY'=>0,'snapToGrid'=>true,
        'scale'=>5,'unit'=>'feet','diagonal'=>'euclidean','useDistanceScale'=>false,
        'crop'=>['x'=>0,'y'=>0,'w'=>1,'h'=>1]];
}
function mapValidateBoardSettings(array $input):array {
    $settings=mapBoardSettingsDefaults();
    foreach(['width'=>[50,20000],'height'=>[50,20000],'cellSize'=>[5,350],'cellSizeY'=>[5,350],'gridOpacity'=>[0,100],'offsetX'=>[-20000,20000],'offsetY'=>[-20000,20000],'scale'=>[0.0001,1000000000]] as $key=>[$min,$max]){
        $value=$input[$key]??$settings[$key];
        if($key==='cellSizeY'&&$value===null)continue;
        if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<$min||(float)$value>$max)throw new DomainException('Valeur invalide pour '.$key.'.');
        $settings[$key]=(float)$value;
    }
    foreach(['boardColor','backgroundColor','dominantColor','gridColor'] as $key){
        $value=$input[$key]??$settings[$key];
        if(!is_string($value)||!preg_match('/^#[a-fA-F0-9]{6}$/D',$value))throw new DomainException('Couleur invalide.');
        $settings[$key]=strtolower($value);
    }
    foreach(['autoDominant','cropOutside','gridVisible','snapToGrid','useDistanceScale'] as $key){
        $value=$input[$key]??$settings[$key];if(!is_bool($value))throw new DomainException('Option invalide.');$settings[$key]=$value;
    }
    foreach(['backgroundMode'=>['image','color'],'gridType'=>['square','hex-v','hex-h','isometric','dimetric'],'unit'=>['feet','meters','kilometers','miles','inches','centimeters','units','hex'],'diagonal'=>['euclidean','manhattan','chebyshev','pathfinder']] as $key=>$values){
        $value=$input[$key]??$settings[$key];if(!in_array($value,$values,true))throw new DomainException('Choix invalide pour '.$key.'.');$settings[$key]=$value;
    }
    $settings['folder']=mapValidateNoteFolder((string)($input['folder']??''))??'';
    $crop=$input['crop']??$settings['crop'];if(!is_array($crop))throw new DomainException('Recadrage invalide.');
    foreach(['x','y','w','h'] as $key){$v=$crop[$key]??null;if(!is_numeric($v)||!is_finite((float)$v))throw new DomainException('Recadrage invalide.');$settings['crop'][$key]=(float)$v;}
    $c=$settings['crop'];
    if($c['x']<0||$c['y']<0||$c['w']<.005||$c['h']<.005||$c['x']+$c['w']>1.000001||$c['y']+$c['h']>1.000001)throw new DomainException('Le recadrage doit rester dans l’image.');
    return $settings;
}
