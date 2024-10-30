<?php
/*
Plugin Name: Japanese Proofreading Preview
Plugin URI:https://wordpress.org/plugins/japanese-proofreading-preview/
Description: 投稿プレビュー画面にて、記事本文の校正支援情報を表示する（Yahoo! APIを使用)
Author: しんさん
Version: 2.0.6
Author URI:https://mobamen.info
*/

//2次元配列の2次元目の配列の値でソートをする関数
function yproofreading_sortArrayByKey( &$array, $sortKey, $sortType = SORT_ASC ) {
    $tmpArray = array();
    foreach ( $array as $key => $row ) {
        $tmpArray[$key] = $row[$sortKey];
    }
    array_multisort( $tmpArray, $sortType, $array );
    unset( $tmpArray );
}



//Yahoo APIに文章校正のリクエストを投げて結果を返す関数
function yproofreading_get_kousei_result($sentence) {
    $api = 'http://jlp.yahooapis.jp/KouseiService/V2/kousei';
    //$appid = get_option('yahoo_appid');

    $id_seeds_a = array( "UdUNWZqVG9Yb0VUWC" , "VB2Y3dLTzZpVHRDei" , "VY4dHZ6SVlBTm4yaC" , "UlWcVNPeksza3N4aS" , "WVQVndiVm1TWEJmVi" , "XRDZGV0Zkl3eW9RTC" , "VZNcUlTM0JYUGwwSC" , "WVQM0Y2UWFObE5pTi" , "Xc3R0syQUltUmZPZS" , "VhTYmNReUhlb2d0bC" );
    $id_seeds_b = array( "OTM" , "N2U" , "NmE" , "OTM" , "YjM" , "ZjM" , "Mzg" , "MDA" , "ZjU" , "MmU" );
    
    $idx = current_time('timestamp') % 10;
    $appid = "dj00aiZpP" . $id_seeds_a[$idx] . "ZzPWNvbnN1bWVyc2VjcmV0Jng9" . $id_seeds_b[$idx] . "-";

    
    $data = array(
      'id'      => '1',
      'jsonrpc' => '2.0',
      'method'  => 'jlp.kouseiservice.kousei',
      'params'  => array(
          'q'  => $sentence
      )
    );
    $json = json_encode($data);
    
    $ch = curl_init($api);
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => "Yahoo AppID: $appid",
        CURLOPT_POSTFIELDS => $json,
    ));
    
    $results = curl_exec($ch);
    curl_close($ch);
    if (false === $results) {
        echo "<div class='proofreading-error'>Internal Server Error</div>";
    }
    $res_json = json_decode($results);
    return $res_json->result;
}

/*
* プレビュー画面の本文上に文章校正支援情報を挿入するフィルター向け関数。
* ※やたらと長い関数になってしまったため、小分けにしても良いかもしれない。
*/
function yproofreading_do_proofreading ($content) {
    //クエリストリング「'proofreading'」の有無で文章構成支援のオンオフを判定
    //プレビュー画面かつ「校正情報プレビュー」ボタンから呼ばれた時にのみ処理を実施
    
    //is_preview()が正常に動作しないケースに遭遇したため、クエリストリングでプレビュー状態かどうか判断しています。
    if(isset($_GET['preview_id']) and isset($_GET['proofreading']) ){
        
        /*
        *記事本文を平文に成形
        *※下記の理由により、preタグ・codeタグ・bblockquoteタグの中身については校正を掛けない仕様にした。
        *・整形済みである要素に校正をかけるのがナンセンスに感じること。
        *・ソースコードはコメントも含めてテスト/検証されているであろうことが予想されるため
        *　「日本語としての良し悪し」で修正を検討することが本来の目的からそれると感じるため
        *・引用文に修正を加えるべきでないこと。
        */
        $sentence = $content;
        /* 記事本文から文章以外の要素を除去*/
        //preタグ・codeタグ・blockquoteタグの中身を削除
        //※
        $sentence = preg_replace( '/\<pre\>.*?\/pre\>/msi','',$sentence);
        $sentence = preg_replace( '/\<code\>.*?\/code\>/msi','',$sentence);
        $sentence = preg_replace( '/\<blockquote\>.*?\/blockquote\>/msi','',$sentence);
        
        $sentence = wp_strip_all_tags($sentence);
        //ショートコードをエスケープ
        //remove_shortcode関数だとショートコードの削除がうまくいかないので正規表現で処理
        //$sentence = remove_shortcode($sentence);
        $sentence = preg_replace( '/\[[^\]]+\]/','',$sentence);
        
        
        /* 力技でテキストを少しきれいに掃除 */
        $sentence = htmlspecialchars($sentence);
        $sentence = str_replace("&amp;nbsp;", "", $sentence);
        $sentence = preg_replace( '/&(([a-zA-Z]{2,}[a-zA-Z0-9]*)|(#[0-9]{2,4})|(#x[a-fA-F0-9]{2,4}))?;/','',$sentence);
        $sentence = html_entity_decode($sentence);
        $sentence =  trim(preg_replace( '/\n+/ms',"\n",$sentence));
        $sentence =  preg_replace( '/ +/',' ',$sentence);
        
        $sentence = stripslashes($sentence);
        
        
        /*
        *配列の準備
        */
        $group = array(
            "誤変換",
            "誤用",
            "使用注意",
            "不快語",
            "機種依存または拡張文字",
            "外国地名",
            "固有名詞",
            "人名",
            "ら抜き",
            "当て字",
            "表外漢字あり",
            "用字",
            "用語言い換え",
            "二重否定",
            "助詞不足の可能性あり",
            "冗長表現",
            "略語",
        );

        $items = array();
        $hash = array();
        $result = array();
        
        //一度に処理する文字列を指定。(APIリクエストの制限サイズが4KBのため4000文字とする)
        $max = 4000;
        //指定文字数内で最大、かつ文章の区切りである(「||」|。|、)が最後に見つかった位置までで分割する。
        $now = 0; // 現在位置
        $total = mb_strlen($sentence, 'UTF-8');
        $sentence_array= array();
        
        while($now <= $total){
            $len = $max;
            $tmp = mb_substr($sentence, $now, $len, 'UTF-8');
            
            //。が最後に見つかった位置
            $pos_punctuation = mb_strrpos($tmp, '。', 0, 'UTF-8');
            //、が最後に見つかった位置
            $pos_comma = mb_strrpos($tmp, '、', 0, 'UTF-8');
            //「が最後に見つかった位置
            $pos_branket1 = mb_strrpos($tmp, '「', 0, 'UTF-8');
            //」が最後に見つかった位置
            $pos_branket2 = mb_strrpos($tmp, '」', 0, 'UTF-8');
            
            if ($pos_punctuation== FALSE){
                $pos_punctuation = 0;
            }
            if ($pos_comma== FALSE){
                $pos_comma = 0;
            }
            if ($pos_branket1== FALSE){
                $pos_branket1 = 0;
            }
            if ($pos_branket2== FALSE){
                $pos_branket2 = 0;
            }
            $pos = max($pos_punctuation , $pos_comma , $pos_branket1 , $pos_branket2);
            if ($pos !== 0){
                // (「||」|。|、)が含まれる場合
                $len = $pos+1;
                $tmp = mb_substr($sentence, $now, $len, 'UTF-8');
            } 
            $sentence_array[] = $tmp;
            $now += $len;
        }


        foreach ($sentence_array as $sentence_part) {
            $results = yproofreading_get_kousei_result($sentence_part);

            foreach ($results->suggestions as $value) {
                $start = (int)$value->offset;
                $len = (int)$value->length;
                if ("" === (string)$value->word) {
                    $surface = mb_substr($sentence, $start, $len);
                } else {
                    $surface = (string)$value->word;
                }
                if ( mb_strlen($surface) == 1 ) {
                    $surface = $surface .  mb_substr($sentence,$start + $len , 1,"UTF-8");
                }
                $word = (string)$value->suggestion;
                if ( mb_strlen($word) == 1 ) {
                    $word = $word . mb_substr($sentence,$start + $len , 1,"UTF-8");
                }
                $note = (string)$value->note;
                $r = array(
                //    'start' => $start,
                //    'end' => $start + $len,
                    'surface' => $surface,
                    'word' => $word,
                    'info' => (string)$value->rule,
                    'note' => (string)$value->note
                //    'index' => array_search((string)$value->ShitekiInfo, $group) + 1
                );
                $h = $r["surface"] . "-" . $r["word"] . "-" . $r["info"];
                if (!in_array($h, $hash)) {
                    $hash[] = $h;
                    $items[] = array("surface" => $r["surface"], "word" => $r["word"], "info" => $r["info"]);
                }
                //$r["item_index"] = array_search($h, $hash) - 1;
                $result[] = $r;
            }   
        }
        
        /*
        *HTML出力の準備
        */
        
        $items_html = <<< EOS
<div class='proofreading-result'>
<div class='proofreading-summary'>
<p><span class='proofreading-h2'>文章校正支援情報</p>
<table  class='proofreading-table'  border="1">
    <tr>
        <th>記事中の表記</th>
        <th>修正候補</th>
        <th>補足</th>
        <th>指摘の種類</th>
    </tr>
EOS;
        $html = $content;
        yproofreading_sortArrayByKey( $items, "info" );
    
    if (count($items) > 0) {
        $items_html .= "<tr>";
        foreach ($items as $item) {
            $items_html .= <<< EOS
    <tr>
        <td>{$item["surface"]}</td>
        <td>{$item["word"]}</td>
        <td>{$item["note"]}</td>
        <td>{$item["info"]}</td>
    </tr>
EOS;
            }
            $items_html .= "</tr>";
        } else {
            $items_html .= "<tr><td colspan='3'>アイテムがありません</td></tr>";
        }
        $items_html .= "</table>";
        $items_html .= <<< EOS
<p><span class='proofreading-h3'>補足：</span></p>
<p class='proofreading-description'>本文中の背景の付いた指摘箇所にマウスを合わせると、ポップアップで指摘事項が表示されます。</p>
<p><span class='proofreading-h3'>見方についての詳細</span></p>
<p class='proofreading-description'>
プラグインについての詳細をまとめたページを用意しましたので<a href="https://mobamen.info/wordpress_proofreading" target="_blank">こちら</a>をごらん下さい。
</p>
<p class='proofreading-description'>
<a href="https://mobamen.info/wordpress_proofreading" target="_blank">WordPress用文章校正プラグイン「Japanese Proofreading Preview」</a>
</p>
</div>
EOS;

	    //指摘対象の文字数で昇順にソートし、長い順に処理する
	    array_multisort( array_map( "strlen", array_column( $items, "surface" ) ), SORT_DESC, $items ) ;
        foreach ($items as $item) {
	            /*本文中の修正候補にspanタグを置換で付ける
            *※置換でspanタグを付ける方法は妥協策で正確性に欠けることを認識している。
            *例えば「経つ」の「経」がひらがなの「た」に置き換え可能という用字の指摘を受けた場合、
            *「経」で置換をかけると「経て」や「経験」等の他の箇所まで置換される。
            *また、preタグやcodeタグの中の文言が置換対象になった時におかしなことになるかもしれない。
            *しかし、代替策が思い浮かばない。
            *文字数のカウントをベースに処理できるように、成形せずに記事本文をAPIに投げることも考えたが、
            *校正結果の精緻度が下がる可能性があるためクレンジングしてから渡す作りにした。
            */
            $info_index = array_search($item["info"], $group) + 1;
            $replaced_ptn  = "<span class='proofreading-item color";
            
            //指摘内容自体が入れ子で置換されてしまうケースがあるため、暫定回避
            $item["info"] = str_replace ('助詞不足の可能性あり','助詞が不足している可能性 あり',$item["info"]);
            $item["info"] = str_replace ('用語言い換え','用語 言い換え',$item["info"]);
            $item["info"] = str_replace ('表外漢字あり','表外漢字 あり',$item["info"]);
            
            $replaced_ptn .= $info_index . "' title='指摘：\n" . str_replace ('あり',' あり',$item["info"]);
            if (!empty($item["word"])) {
                $replaced_ptn .= "\n修正候補：\n" . $item["word"];
            }
            if (!empty($item["note"])) {
                $replaced_ptn .= "\n補足：\n" . $item["note"];
            }
            $replaced_ptn .= "'>" . $item["surface"] . "</span>";
            
            $html = str_replace ($item["surface"],$replaced_ptn,$html);
        }
    
        // ※記事中のハイライト機能は妥協してtile属性で代替
        // ※誰かtoolstipをcssとjavascriptで表示するように修正してくれないだろうか。
        return $items_html .$html . '</div>';
    } else {
        return $content;
    }
}

/*
*cssリンクをヘッダーに追加する
*/
function yproofreading_enqueue_css () {
    //プレビュー画面かつ「校正情報プレビュー」ボタンから呼ばれた時にのみ処理を実施
    //is_preview()が正常に動作しないケースに遭遇したため、クエリストリングでプレビュー状態かどうか判断しています。
    if(isset($_GET['preview_id']) and isset($_GET['proofreading']) ){
        wp_register_style(
            'proofreading',
            plugins_url('css/proofreading.css', __FILE__),
            array(),
            1.0,
            'all'
        );
        wp_enqueue_style('proofreading');
    }
}
//クエリストリングより文章構成支援のオンオフを判定し、必用な時のみアクションとフィルターをフックする。
//※条件を絞らないとフックされる機会が多すぎるのでは無いかと考えたため。
if( isset($_GET['proofreading']) ){
    add_action('wp_enqueue_scripts', 'yproofreading_enqueue_css');
    add_filter('the_content','yproofreading_do_proofreading');
}

/* 校正情報プレビューボタン表示 */
function yproofreading_add_proofreading_preview_button() {
    
    global $post;
    // wp-admin/includes/post.phpよりコードを拝借。
    $query_args = array();
    if ( get_post_type_object( $post->post_type )->public ) {
        if ( 'publish' == $post->post_status || $user->ID != $post->post_author ) {
            // Latest content is in autosave
            $nonce = wp_create_nonce( 'post_preview_' . $post->ID );
            $query_args['preview_id'] = $post->ID;
            $query_args['preview_nonce'] = $nonce;
        }
    }
    //判別用にクリエストリング「proofreading=yes」を追加
    $query_args['preview'] = 'true';
    $query_args['proofreading'] = 'yes';
    $url = html_entity_decode(esc_url(add_query_arg($query_args, get_permalink($page->ID))));
?>
<script>
    (function($) {
        $('#minor-publishing-actions').append('<div class="proofreading-preview"><a id="proofreading-preview" class="button">校正情報付きプレビュー</a></div>');
        $(document).on('click', '#proofreading-preview', function(e) {
            e.preventDefault();
            PreviewURL = '<?php echo $url ?>';
            window.open(PreviewURL);
        });
    }(jQuery));
</script>
<?php
}
add_action( 'admin_footer-post-new.php', 'yproofreading_add_proofreading_preview_button' );
add_action( 'admin_footer-post.php', 'yproofreading_add_proofreading_preview_button' );
