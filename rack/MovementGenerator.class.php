<?php


/**
 * Генератор на движения в палетния склад
 *
 *
 * @category  bgerp
 * @package   rack
 *
 * @author    Ivelin Dimov <ivelin_pdimov@abv.bg>
 * @copyright 2006 - 2018 Experta OOD
 * @license   GPL 3
 *
 * @since     v 0.1
 */
class rack_MovementGenerator extends core_Manager
{
    /**
     * Плъгини за зареждане
     */
    public $loadList = 'rack_Wrapper';
    
    
    /**
     * Генератор на движения
     */
    public $title = 'Генератор на движения';
    
    
    /**
     * Какъв процент от количеството трябва да е на палета, за да го смятаме за почти пълен?
     */
    const ALMOST_FULL = 0.85;
    
    
    /**
     * Екшън за тест
     */
    public function act_Default()
    {
        requireRole('debug');
        $form = cls::get('core_Form');
        $form->FLD('pallets', 'table(columns=pallet|quantity,captions=Палет|Количество,widths=8em|8em)', 'caption=Палети,mandatory');
        $form->FLD('zones', 'table(columns=zone|quantity,captions=Зона|Количество,widths=8em|8em)', 'caption=Зони,mandatory');
        $form->FLD('smallZonesPriority', 'enum(yes=Да,no=Не)', 'caption=Приоритетност на малките количества->Избор');
        
        $form->toolbar = cls::get('core_Toolbar');
        $form->toolbar->addSbBtn('Изпрати');
        
        $rec = $form->input();
        
        $invArr = $payArr = array();
        
        if ($form->isSubmitted()) {
            $pArr = json_decode($rec->pallets);
            $qArr = json_decode($rec->zones);

            foreach ($pArr->pallet as $i => $key) {
                if ($pArr->quantity[$i]) {
                    $p[$key] = core_Type::getByName('double')->fromVerbal($pArr->quantity[$i]);
                }
            }

            foreach ($qArr->zone as $i => $key) {
                if ($qArr->quantity[$i]) {
                    $q[$key] = core_Type::getByName('double')->fromVerbal($qArr->quantity[$i]);
                }
            }

            $mArr = self::mainP2Q($p, $q, null, $rec->smallZonesPriority);
        }
        
        $form->title = 'Генериране на движения по палети';
        
        $html = $form->renderHtml();
        
        if (countR($p)) {
            $html .= '<h2>Палети</h2>';
            $html .= ht::mixedToHtml($p);
        }
        
        if (countR($q)) {
            $html .= '<h2>Зони</h2>';
            $html .= ht::mixedToHtml($q);
        }
        
        if (countR($mArr)) {
            $html .= '<h2>Движения</h2>';
            $html .= ht::mixedToHtml($mArr);
        }
        
        $html = $this->renderWrapping($html);
        
        return $html;
    }
    
    
    /**
     * Входната точка на алгоритъма за изчисляване на движенията
     */
    public static function mainP2Q($p, $z, $quantityPerPallet = null, $smallZonesPriority = false)
    {
        $smallZonesPriority = ($smallZonesPriority == 'yes' || $smallZonesPriority === true) ? true : false;
        
        asort($p);
        asort($z);
        
        $pOrg = $p;
        
        // Ако малките количества са с приоритет, в случай на недостиг - орязваме големите
        if ($smallZonesPriority) {
            $sumP = array_sum($p);
            $sumZ = array_sum($z);
            
            if ($sumZ > $sumP) {
                foreach ($z as $zI => $zQ) {
                    $sumP -= $zQ;
                    if ($sumP < 0) {
                        $z[$zI] += $sumP;
                        $sumP = 0;
                    }
                }
            }
        }
        
        $moves = array();
        
        do {
            $fullPallets = self::getFullPallets($p, $quantityPerPallet);
          
            // На всяка стъпка вземаме по един палет и го разпределяме
            $res = self::p2q($p, $z, $fullPallets, $quantityPerPallet);
 
            $moves = arr::combine($moves, $res);
            $i++;
            if ($i > 100) {
                // временна защита срещу безкраен цикъл;
                expect(false, $res);
            }
        } while (countR($res) > 0);
        

        $res = array();
        $i = 0;
        foreach ($moves as $m => $q) {
            list($l, $r) = explode('=>', $m);
            if ($l == 'get') {
                $i++;
                $res[$i] = new stdClass();
                $o = &$res[$i];
                $o->pallet = $r;
                $o->quantity = $q;
                $o->zones = array();
            }
            if ($l == $o->pallet) {
                $o->zones[$r] = $q;
            }
            if ($l == 'ret') {  
                // Ако върнатото количество е над 80% от палета, приемаме, че е по-добре да вземем
                // само това, което ни трябва за зоните. Тук трябва да се проеми това ограничение по зададено максимално тегло
                // на вземането от палета, което може да стане ръчно. Функцията трябва да получава макс количество,
                // при което не се взема целия палет, а само необходимата част
                // $q - какво трябва да върнем
                if ((($o->quantity / 4 > $q) && !self::isFirstRow($o->pallet)) || $quantityPerPallet && $q > 0 && ($q <= (1-self::ALMOST_FULL) * $quantityPerPallet)) {
                     
                    $o->ret = $q;
                    
                    // Къде да е върнат палета?
                    // Първо добавяме нулевите палети
                    foreach ($pOrg as $pI => $pQ) {
                        if (!isset($p[$pI])) {
                            $p[$pI] = 0;
                        }
                    }
                    
                    // Търси палет на първия ред, който има най-малко бройки
                    foreach ($p as $pI => $pQ) {
                        if(!isset($minPq) || ($pQ < $minPq)) {
                            $o->retPos = $pI; 
                            $minPq = $pQ;
                        }                        
                            
                        if (self::isFirstRow($pI)) {
                            
                            if (($quantityPerPallet) && 
                                ($quantityPerPallet >= self::ALMOST_FULL * ($pQ + $q))) {
                           
                                $o->retPos = $pI;
                                
                                break;
                            }
                        }
                    }
                } else {
                    $o->quantity = array_sum($o->zones);
                }
            }
        }
    
        return $res;
    }


    /**
     * Автоматично самотестване
     */
    public function act_Test2()
    {
        // Вземаме от по-малкият палет
        $p = ['1a1' => 200, '1b1' => 100];
        $q = ['z1' => 50, 'z2' => 40];
        $mArr = self::mainP2Q($p, $q);
        expect($mArr[1]->pallet == '1b1', $p, $q, $mArr);
        
        // Ако 
        $p = ['1c1' => 200, '1b1' => 100, '1a1' => 120];
        $q = ['z1' => 50, 'z2' => 90];
        $mArr = self::mainP2Q($p, $q);
        expect($mArr[1]->pallet == '1c1', $p, $q, $mArr);

        // В два палета има точно, колкото трябват
        $p = ['1a2' => 200, '1b1' => 100, '1a1' => 40];
        $q = ['z1' => 140];
        $mArr = self::mainP2Q($p, $q);
        expect($mArr[2]->pallet == '1a1', $p, $q, $mArr);
        expect($mArr[1]->pallet == '1b1', $p, $q, $mArr);

        // В два палета има точно, колкото трябват
        $p = ['1a1' => 200, '1b1' => 150, '1c1' => 150, '1d1' => 200];
        $q = ['z1' => 190];
        $mArr = self::mainP2Q($p, $q);
        expect($mArr[1]->quantity == 200, $p, $q, $mArr);
        expect($mArr[1]->retPos == '1a1', $p, $q, $mArr);

        return 'OK';
    }
    
    
    /**
     * Връща масив от масиви. Вторите масиви, са движения, които изчепват или P или Q
     * 
     * @param array $p позициите на които има дадения продукт => наличността на дадената позиция
     * @param array $z зоните където се търси дадения продукт => количеството, което се търси
     */
    public static function p2q(&$p, &$z, $fullPallets, $quantityPerPallet)
    {
        $moves = array();
        
        if (!countR($p) || !countR($z)) {
            
            return $moves;
        }
        
        asort($p);
        asort($z);
        $sumZ = array_sum($z); // Количество за всички зони 
        
        /* 
        // Вземаме от най-ниския палет, с изключение на случаиите, когато, количеството което трябва да оставим е по-голямо от 0.8 от цял палет.
        if ( $quantityPerPallet > 0 && $quantityPerPallet * self::ALMOST_FULL >= $sumZ) {
            foreach ($p as $pos => $q) {
                if (self::isFirstRow($pos)) {
                    unset($p[$pos]);
                    $p = array_merge(array($pos => $q), $p);
                    break;
                }
            }
        }
        */
        
        $pCombi = array();
        $pR = $p;
        krsort($pR);
        $cnt = countR($p);
        while ($cnt-- > 0 && countR($pCombi) < 20000) {
            $pCombi = self::addCombi($pR, $pCombi);
        }
        
        $zCombi = array();
        $cnt = countR($z);
        while ($cnt-- > 0 && countR($zCombi) < 20000) {
            $zCombi = self::addCombi($z, $zCombi);
        }

        // Подреждаме от най-големите комбинации към най-малките
        krsort($zCombi);
 
        // Вкарваме точните съответсвия на комбинации
        foreach ($pCombi as $pQ => $pK) {
            if ($zK = $zCombi["{$pQ}"]) {  
                $moves = self::moveGen($p, $z, $pK, $zK);
                break;
            }
        }
      
        if (!countR($moves)) {
            $zR = array_reverse($z, true);
            foreach ($fullPallets as $i => $pQ) {
                if ($pQ <= 0) {
                    continue;
                }
                foreach ($zR as $j => $zQ) {
                    if ($zQ >= $pQ) {
                        $moves["get=>{$i}"] = $pQ;
                        $moves["${i}=>{$j}"] = $pQ;
                        $z[$j] -= $p[$i];
                        unset($p[$i]);
                        if ($z[$j] == 0) {
                            unset($z[$j]);
                        }
                        break 2;
                    }
                }
            }
        }
       
        if (!countR($moves)) {
            $kZ = '';  
            $t = 0;
            $zR = array_reverse($z, true);
            foreach ($zR as $zI => $zQ) {
                $zK .= ($zK == '' ? '|' : '') .$zI . '|';
                $t += $zQ;
            }
        
            if ($t) { 
                foreach ($pCombi as $pQ => $pK) {
                    if ($pQ >= $t) {
                        break;
                    }
                }
           
                $moves = self::moveGen($p, $z, $pK, $zK);
            }
        }
          
        return $moves;
    }
    
    
    /**
     * Изчислява най-големият общ делител на $a и $b
     */
    public static function gcd($a, $b)
    {
        return ($a % $b) ? self::gcd($b, $a % $b) : $b;
    }


    /**
     * Проверява дали позицията е не първи ред
     */
    public static function isFirstRow($pos)
    {
        return rack_MovementGenerator2::isFirstRow($pos);
    }
    
    
    /**
     * Генерира движение на база зададени кейлистове за палети и зони до пълни изчерпване
     */
    private static function moveGen(&$p, &$z, $pK, $zK)
    {
        $moves = array();
        
        $pK = explode('|', trim($pK, '|'));
        $zK = explode('|', trim($zK, '|'));
        
        foreach ($pK as $pI) {  
            $pQ = (float) $p[$pI];  
            if ($pQ <= 0) {
                continue;
            }
            $moves["get=>{$pI}"] = $pQ;
            foreach ($zK as $zI) {
                $zQ = (float) $z[$zI];  
                if ($zQ <= 0) {
                    continue;
                }
                if ($pQ <= 0) {
                    continue;
                }
                
                $q = min($zQ, $pQ);
                
                $moves["{$pI}=>{$zI}"] = $q ;
                $pQ = $p[$pI] -= $q;
                $zQ = $z[$zI] -= $q;
                
                if ($p[$pI] == 0) {
                    unset($p[$pI]);
                }
                if ($z[$zI] == 0) {
                    unset($z[$zI]);
                }
            }
        }
        
        if ($pQ > 0) {
            $moves["ret=>{$pI}"] = $pQ;
        }
        
        return $moves;
    }
    
    
    /**
     * Добавя комбинации с ключове/стойности от следващо ниво
     */
    private static function addCombi($arr, $combi = null)
    {
        foreach ($combi ? $combi : array(0 => '|') as $mK => $m) {
            foreach ($arr as $k => $qK) {
                if (strpos($m, '|'. $k . '|') === false) {
                    $qnt = $mK + $qK;
                    if (!$combi[$qnt]) {
                        $combi[$qnt] = $m  . $k. '|';
                    }
                }
            }
        }
        
        return $combi;
    }
    
    
    /**
     * Връща всички цели палети, ако има такива
     * Ако не се подаде параметъра за количество на цял палет, се опитва да
     * намери целите палети, като палетите с най-често повтарящо се количество
     */
    public static function getFullPallets($pallets, &$quantityPerPallet = null)
    {
        if (!$quantityPerPallet) {
            $cnt = array();
            foreach ($pallets as $i => $iP) {
                $cnt[$iP]++;
            }
            
            arsort($cnt);
            $best = key($cnt);
            foreach($cnt as $q => $n) {
                if($q != $best) unset($ctn[$q]);
            }

            krsort($cnt);
            $best = key($cnt);

            if ($cnt[$best] > 1) {
                $quantityPerPallet = $best;
            }
        }
        
        $res = array();
        
        if ($quantityPerPallet > 0) {
            $res = array();
            foreach ($pallets as $i => $iP) {
                if ($iP >= $quantityPerPallet) {
                    $res[$i] = (float) $iP;  
                }
            }
        }
        
        return $res;
    }


    /**
     * Връща масив с генерирани движения, на базата на функцията rack_MovementGenerator::mainP2Q
     * 
     * @param array    $allocatedArr Масив с резултата от фунцията mainP2Q
     * @param int      $productId ID на продукта
     * @param int      $packagingId ID на опаковката
     * @param string   $batch Партида
     * @param int      $storeId ИД na склада
     * @param int|null $workerId - ид на потребител
     *
     * @return array
     */
    public static function getMovements($allocatedArr, $productId, $packagingId, $batch, $storeId, $workerId = null)
    {
        $res = array();
        if (!is_array($allocatedArr)) {
            
            return $res;
        }

        $packRec = cat_products_Packagings::getPack($productId, $packagingId);
        $quantityInPack = is_object($packRec) ? $packRec->quantity : 1;
        
        foreach ($allocatedArr as $obj) {
            $newRec = (object) array('productId' => $productId,
                'packagingId' => $packagingId,
                'storeId' => $storeId,
                'quantityInPack' => $quantityInPack,
                'state' => isset($workerId) ? 'waiting' : 'pending',
                'brState' => isset($workerId) ? 'pending' : 'null',
                'batch' => $batch,
                'workerId' => $workerId,
                'quantity' => $obj->quantity,
                'position' => $obj->pallet,
            );
            
            if ($palletRec = rack_Pallets::getByPosition($obj->pallet, $storeId, $productId)) {
                $newRec->palletId = $palletRec->id;
                $newRec->palletToId = $palletRec->id;
                $newRec->batch = $palletRec->batch;
                $newRec->positionTo = ($obj->retPos) ? $obj->retPos : $obj->pallet;
            } else {
                // Липсва палет в движението
                wp($allocatedArr, $productId, $packagingId, $batch);
            }
            
            if(!countR($obj->zones)){
                wp($allocatedArr, $productId, $packagingId, $batch);
                continue;
            }
            
            $zoneArr = array('zone' => array(), 'quantity' => array());
            foreach ($obj->zones as $zoneId => $zoneQuantity) {
                $zoneArr['zone'][] = $zoneId;
                $zoneArr['quantity'][] = $zoneQuantity / $quantityInPack ;
            }
            
            $TableType = core_Type::getByName('table(columns=zone|quantity,captions=Зона|Количество)');
            $newRec->zones = $TableType->fromVerbal($zoneArr);
            
            $res[] = $newRec;
        }

        return $res;
    }
}
