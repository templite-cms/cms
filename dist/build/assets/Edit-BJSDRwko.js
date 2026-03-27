import{_ as ke,a as J}from"./EditSidePanel-BFTZCgtG.js";import{_ as he}from"./CodeEditor-De-L3mx1.js";import{_ as Q}from"./TextField-BlXlwWsx.js";import{_ as Ce}from"./CheckboxField-CNnfM1sD.js";import{_ as we}from"./SlugField-BpBJsMXI.js";import{_ as Pe}from"./MediaPicker-DDN5s3qo.js";import{d as Se,u as Te,I as Ae,a as qe}from"./DateField.vue_vue_type_style_index_0_lang-BFhdkW_K.js";import{u as De}from"./useApi-C0u-srqq.js";import{g as j,u as Me}from"./useCmsUrl-BMH6Mdh_.js";import{r as k,w as Ve,v as c,x as w,p as x,z as o,l as e,s as I,k as _,A as Re,D as U,B as f,f as R,F as B,y as E,q as b,E as s,K as Ue,L as Be,V as Ee}from"./x-CuOWXDIc.js";import{C as S}from"./check-D7sgkfv8.js";import{C as T,G as Fe,a as ze}from"./lucide-vue-next-DqYmV2Wg.js";import{_ as V}from"./Accordion-BbATSDTQ.js";import{u as Ie}from"./useUnsavedChanges-BW4Wkrng.js";import{S as We}from"./zap-Dlr53vCB.js";const W=Se("actionEditor",()=>{const P=k(null),i=k([]),l=k({name:"",slug:"",description:"",code:"",screen:null,allow_http:!1}),r=k({}),h=k(null),y=k(null),m=k(320),v=k(!1),t=k(!1),a=k(360),D=k(!1),A=k(!1),$=k(!1);let n=null;function d(u){var C,M,H,L,O,G,K,N;P.value=u.action,i.value=u.globalFieldDefinitions||[],l.value={name:((C=u.action)==null?void 0:C.name)||"",slug:((M=u.action)==null?void 0:M.slug)||"",description:((H=u.action)==null?void 0:H.description)||"",code:((L=u.action)==null?void 0:L.code)||u.defaultCode||"",screen:((G=(O=u.action)==null?void 0:O.screen)==null?void 0:G.id)??((K=u.action)==null?void 0:K.screen)??null,allow_http:((N=u.action)==null?void 0:N.allow_http)??!1},h.value=null,y.value=null,A.value=!1,$.value=!1,r.value={},p(),n&&n();const q=JSON.stringify(l.value);n=Ve(l,()=>{$.value=JSON.stringify(l.value)!==q},{deep:!0})}function p(){try{const u=localStorage.getItem("action_editor_side_panel_width");if(u){const C=parseInt(u,10);C>=250&&C<=800&&(m.value=C)}const q=localStorage.getItem("action_editor_docs_panel_width");if(q){const C=parseInt(q,10);C>=250&&C<=800&&(a.value=C)}}catch{}}function g(){try{localStorage.setItem("action_editor_side_panel_width",String(m.value)),localStorage.setItem("action_editor_docs_panel_width",String(a.value))}catch{}}function F(u){h.value=h.value===u?null:u}function de(){h.value=null}function re(u){y.value=y.value===u?null:u}function pe(){y.value=null}function ue(u){m.value=Math.max(250,Math.min(u,window.innerWidth*.5)),g()}function _e(){t.value=!t.value}function me(){t.value=!1}function ye(u){a.value=Math.max(250,Math.min(u,window.innerWidth*.4)),g()}const{post:ge,put:fe,del:be}=De(),z=Te();async function ve(){var u;A.value=!0,r.value={};try{const q={name:l.value.name,slug:l.value.slug,description:l.value.description,code:l.value.code,screen:l.value.screen,allow_http:l.value.allow_http};if((u=P.value)!=null&&u.id)await fe(`/api/cms/actions/${P.value.id}`,q);else{const C=await ge("/api/cms/actions",q),M=(C==null?void 0:C.data)||C;if(M!=null&&M.id){window.location.href=j(`/actions/${M.id}/edit`);return}}$.value=!1,z.success("Action сохранён")}catch(q){q.errors&&(r.value=q.errors,["name","slug"].some(M=>r.value[M])&&!y.value&&(y.value="main")),z.error("Ошибка при сохранении")}finally{A.value=!1}}async function xe(){var u;if((u=P.value)!=null&&u.id)try{await be(`/api/cms/actions/${P.value.id}`),z.success("Action удалён"),window.location.href=j("/actions")}catch{z.error("Ошибка удаления")}}function $e(){n&&(n(),n=null),P.value=null,i.value=[],l.value={name:"",slug:"",description:"",code:"",screen:null,allow_http:!1},r.value={},h.value=null,y.value=null,m.value=320,v.value=!1,t.value=!1,a.value=360,D.value=!1,A.value=!1,$.value=!1}return{$reset:$e,action:P,globalFieldDefinitions:i,form:l,errors:r,activeMegaMenu:h,activeSidePanel:y,sidePanelWidth:m,isSidePanelResizing:v,isDocsPanelOpen:t,docsPanelWidth:a,isDocsPanelResizing:D,isSaving:A,isDirty:$,init:d,toggleMegaMenu:F,closeMegaMenu:de,toggleSidePanel:re,closeSidePanel:pe,setSidePanelWidth:ue,toggleDocsPanel:_e,closeDocsPanel:me,setDocsPanelWidth:ye,saveAction:ve,deleteAction:xe}}),He={class:"action-main-fields"},Le={key:0,class:"action-main-fields__endpoint"},Oe={class:"action-main-fields__endpoint-row"},Ge=["value"],Ke={key:1,class:"action-main-fields__source"},Ne={class:"action-main-fields__source-badge"},Je={__name:"ActionMainFields",setup(P){const i=W(),l=R(()=>i.form.slug?`/action/${i.form.slug}`:""),r=k(!1);function h(){if(!l.value)return;const y=window.location.origin+l.value;navigator.clipboard.writeText(y).then(()=>{r.value=!0,setTimeout(()=>{r.value=!1},1500)})}return(y,m)=>{var v;return c(),w("div",He,[x(Q,{modelValue:o(i).form.name,"onUpdate:modelValue":m[0]||(m[0]=t=>o(i).form.name=t),label:"Название",required:"",error:o(i).errors.name,placeholder:"get_latest_posts"},null,8,["modelValue","error"]),x(we,{modelValue:o(i).form.slug,"onUpdate:modelValue":m[1]||(m[1]=t=>o(i).form.slug=t),"source-value":o(i).form.name,error:o(i).errors.slug},null,8,["modelValue","source-value","error"]),x(Q,{modelValue:o(i).form.description,"onUpdate:modelValue":m[2]||(m[2]=t=>o(i).form.description=t),label:"Описание",type:"textarea",rows:3,placeholder:"Описание того, что делает этот action..."},null,8,["modelValue"]),x(Pe,{modelValue:o(i).form.screen,"onUpdate:modelValue":m[3]||(m[3]=t=>o(i).form.screen=t),label:"Скриншот",type:"image"},null,8,["modelValue"]),x(Ce,{modelValue:o(i).form.allow_http,"onUpdate:modelValue":m[4]||(m[4]=t=>o(i).form.allow_http=t),label:"Доступен по HTTP"},null,8,["modelValue"]),o(i).form.allow_http&&o(i).form.slug?(c(),w("div",Le,[m[5]||(m[5]=e("label",{class:"action-main-fields__endpoint-label"},"Эндпоинт",-1)),e("div",Oe,[e("input",{type:"text",value:l.value,readonly:"",class:"action-main-fields__endpoint-input"},null,8,Ge),e("button",{type:"button",class:I(["action-main-fields__endpoint-copy",r.value?"action-main-fields__endpoint-copy--success":"action-main-fields__endpoint-copy--idle"]),title:"Скопировать URL",onClick:h},[(c(),_(Re(r.value?o(S):o(T)),{class:"icon-4"}))],2)])])):U("",!0),(v=o(i).action)!=null&&v.source?(c(),w("div",Ke,[m[6]||(m[6]=e("span",{class:"action-main-fields__source-label"},"Источник:",-1)),e("span",Ne,f(o(i).action.source),1)])):U("",!0)])}}},Qe={class:"variables-panel"},je={key:0,class:"variables-panel__section-count"},Xe={class:"variables-panel__section-body"},Ye={key:0,class:"variables-panel__items"},Ze=["onClick","onDragstart"],et={class:"list-item-min__code"},tt={key:0,class:"list-item-min__type"},lt={class:"list-item-min__name"},ot={class:"list-item-min__action"},at={__name:"ActionVariablesPanel",setup(P){const i=W(),l=k({0:!0,1:!0,2:!1,3:!1,4:!1}),r=k(null);async function h(n,d){try{await navigator.clipboard.writeText(d),r.value=n,setTimeout(()=>{r.value=null},2e3)}catch{const p=document.createElement("textarea");p.value=d,document.body.appendChild(p),p.select(),document.execCommand("copy"),document.body.removeChild(p),r.value=n,setTimeout(()=>{r.value=null},2e3)}}function y(n,d){n.dataTransfer.setData("text/plain",d),n.dataTransfer.setData("application/x-action-variable",""),n.dataTransfer.effectAllowed="copy"}const m=[{code:"$context->page",desc:"Объект Page",type:"Page"},{code:"$context->request",desc:"HTTP-запрос",type:"Request"},{code:"$context->global",desc:"Глобальные поля",type:"array"},{code:"$context->blockData",desc:"Данные полей блока",type:"array"},{code:"$result",desc:"Результат (возвращаемое значение)",type:"mixed"}],v=[{code:"$context->page->title",desc:"Заголовок страницы",type:"string"},{code:"$context->page->url",desc:"URL страницы",type:"string"},{code:"$context->page->slug",desc:"Slug страницы",type:"string"},{code:"$context->page->seo_data",desc:"SEO-данные",type:"array"},{code:"$context->page->img",desc:"Изображение страницы",type:"File|null"},{code:"$context->page->status",desc:"Статус публикации",type:"string"},{code:"$context->page->views",desc:"Количество просмотров",type:"int"},{code:"$context->page->parent",desc:"Родительская страница",type:"Page|null"},{code:"$context->page->children",desc:"Дочерние страницы",type:"Collection"}],t=[{code:"$context->request->get('key')",desc:"Получить параметр",type:"mixed"},{code:"$context->request->input('key')",desc:"Получить input",type:"mixed"},{code:"$context->request->all()",desc:"Все параметры",type:"array"},{code:"$context->request->has('key')",desc:"Проверка наличия",type:"bool"},{code:"$context->request->query('key')",desc:"Query-параметр",type:"mixed"},{code:"$context->request->boolean('key')",desc:"Boolean-параметр",type:"bool"}],a=R(()=>i.globalFieldDefinitions.map(n=>({code:`$context->global['${n.key}']`,desc:n.name||n.key,type:n.type||"mixed"}))),D=[{code:"$context->blockData['fieldKey']",desc:"Значение поля блока",type:"mixed"}],A=R(()=>[{index:0,title:"Контекст",items:m,prefix:"ctx",alwaysShow:!0},{index:1,title:"Страница",items:v,prefix:"page",alwaysShow:!0},{index:2,title:"Запрос",items:t,prefix:"req",alwaysShow:!0},{index:3,title:"Глобальные поля",items:a.value,prefix:"g"},{index:4,title:"Данные блока",items:D,prefix:"bd",alwaysShow:!0}]),$=R(()=>A.value.filter(n=>n.alwaysShow||n.items.length>0));return(n,d)=>(c(),w("div",Qe,[(c(!0),w(B,null,E($.value,p=>(c(),_(V,{key:p.index,expanded:l.value[p.index],"onUpdate:expanded":g=>l.value[p.index]=g},{trigger:b(()=>[s(f(p.title)+" ",1),p.items.length?(c(),w("span",je,"("+f(p.items.length)+")",1)):U("",!0)]),default:b(()=>[e("div",Xe,[p.items.length?(c(),w("div",Ye,[(c(!0),w(B,null,E(p.items,g=>(c(),w("div",{key:g.code,draggable:"true",class:"list-item-min",onClick:F=>h(p.prefix+"-"+g.code,g.code),onDragstart:F=>y(F,g.code)},[x(o(Fe),{class:"list-item-min__drag"}),e("code",et,f(g.code),1),g.type?(c(),w("span",tt,f(g.type),1)):U("",!0),e("span",lt,f(g.desc),1),e("span",ot,[r.value===p.prefix+"-"+g.code?(c(),_(o(S),{key:0,class:"list-item-min__action-icon--success"})):(c(),_(o(T),{key:1,class:"list-item-min__action-icon"}))])],40,Ze))),128))])):U("",!0)])]),_:2},1032,["expanded","onUpdate:expanded"]))),128))]))}},nt={class:"docs-panel"},st={class:"docs-panel__code-block"},it={class:"docs-panel__code-block"},ct={class:"docs-panel__code-block"},dt={class:"docs-panel__code-block"},rt={class:"docs-panel__code-block"},pt={class:"docs-panel__table"},ut={class:"docs-panel__table-tbody"},_t={class:"docs-panel__table-td"},mt={class:"docs-panel__inline-code"},yt={class:"docs-panel__table-td"},gt={class:"docs-panel__table"},ft={class:"docs-panel__table-tbody"},bt={class:"docs-panel__table-td"},vt={class:"docs-panel__inline-code"},xt={class:"docs-panel__table-td"},$t={class:"docs-panel__code-block"},kt={class:"docs-panel__code-block"},ht={class:"docs-panel__code-block"},Ct={class:"docs-panel__code-block"},wt={class:"docs-panel__code-block"},Pt={class:"docs-panel__code-block"},St={class:"docs-panel__code-block"},Tt={class:"docs-panel__table"},At={class:"docs-panel__table-tbody"},qt={class:"docs-panel__table-td"},Dt={class:"docs-panel__inline-code"},Mt={class:"docs-panel__table-td docs-panel__table-td--nowrap docs-panel__table-td--muted",style:{"font-family":"var(--font-mono)","font-size":"10px"}},Vt={class:"docs-panel__table-td"},X=`storage/cms/actions/
  my-action.php         -- PHP-класс действия

app/Actions/
  MyAction.php          -- код разработчика (приоритет выше)`,Y=`<?php

namespace App\\Actions;

use Templite\\Cms\\Contracts\\ActionContext;
use Templite\\Cms\\Contracts\\BlockActionInterface;

class MyAction implements BlockActionInterface
{
    /**
     * Входные параметры — генерируют UI-форму в админке
     * при привязке действия к блоку.
     *
     * @return array<string, array>
     */
    public function params(): array
    {
        return [
            // 'limit' => ['type' => 'number', 'label' => 'Количество', 'default' => 10],
        ];
    }

    /**
     * Описание возвращаемых данных — подсказки в редакторе
     * кода блока и документация.
     *
     * @return array<string, array{type: string, description: string}>
     */
    public function returns(): array
    {
        return [
            // 'items' => ['type' => 'Collection<Page>', 'description' => 'Список страниц'],
        ];
    }

    /**
     * CSRF-проверка при HTTP-вызове (/action/{slug}).
     *
     * true  — POST-запросы требуют _token (формы на своём сайте)
     * false — без CSRF (внешние вызовы, webhook, fetch с другого домена)
     *
     * При отключении действует throttle + honeypot.
     */
    public function csrfEnabled(): bool
    {
        return true;
    }

    /**
     * Основная логика действия.
     *
     * @param  array          $params   Параметры из params()
     * @param  ActionContext  $context  Контекст: page, request, global, blockData
     * @return array  Данные для Blade-шаблона блока
     */
    public function handle(array $params, ActionContext $context): array
    {
        return [];
    }
}`,Z=`// ActionContext -- объект контекста, передаётся вторым аргументом в handle()

$context->page        // Page      -- текущая страница
$context->request     // Request   -- HTTP-запрос
$context->global      // array     -- глобальные поля (key => value)
$context->blockData   // array     -- данные текущего блока (значения полей)`,ee=`// Свойства $context->page (модель Page)
$context->page->id
$context->page->title
$context->page->slug
$context->page->full_url       // полный путь (/catalog/item)
$context->page->parent_id
$context->page->page_type_id
$context->page->is_published
$context->page->meta_title
$context->page->meta_description
$context->page->created_at
$context->page->updated_at`,te=`// Свойства $context->request (HTTP-запрос)
$context->request->query('page')      // GET-параметр ?page=2
$context->request->query('search')    // GET-параметр ?search=...
$context->request->all()              // все параметры
$context->request->ip()               // IP клиента
$context->request->url()              // текущий URL`,le=`public function params(): array
{
    return [
        'page_type' => [
            'type' => 'select',
            'label' => 'Тип страницы',
            'options' => 'page_types',
            'required' => true,
        ],
        'limit' => [
            'type' => 'number',
            'label' => 'Количество',
            'default' => 10,
        ],
        'order_by' => [
            'type' => 'select',
            'label' => 'Сортировка',
            'options' => [
                'created_at' => 'По дате',
                'title' => 'По названию',
                'order' => 'По порядку',
            ],
            'default' => 'created_at',
        ],
        'show_unpublished' => [
            'type' => 'checkbox',
            'label' => 'Показывать неопубликованные',
            'default' => false,
        ],
    ];
}`,oe=`public function returns(): array
{
    return [
        'items' => [
            'type' => 'Collection<Page>',
            'description' => 'Коллекция страниц',
        ],
        'total' => [
            'type' => 'int',
            'description' => 'Общее количество',
        ],
        'has_more' => [
            'type' => 'bool',
            'description' => 'Есть ли ещё страницы',
        ],
    ];
}`,ae=`public function handle(array $params, ActionContext $context): array
{
    $limit = $params['limit'] ?? 10;
    $orderBy = $params['order_by'] ?? 'created_at';

    $query = Page::where('is_published', true);

    // Фильтр по типу страницы
    if (!empty($params['page_type'])) {
        $query->where('page_type_id', $params['page_type']);
    }

    $total = $query->count();

    $items = $query
        ->orderBy($orderBy, 'desc')
        ->limit($limit)
        ->get();

    return [
        'items' => $items,
        'total' => $total,
        'has_more' => $total > $limit,
    ];
}`,ne=`// Пагинация через query-параметры
public function handle(array $params, ActionContext $context): array
{
    $perPage = $params['per_page'] ?? 12;
    $page = (int) $context->request->query('page', 1);

    $paginator = Page::where('is_published', true)
        ->orderBy('created_at', 'desc')
        ->paginate($perPage, ['*'], 'page', $page);

    return [
        'items' => $paginator->items(),
        'paginator' => $paginator,
    ];
}`,se=`// Дочерние страницы текущей
public function handle(array $params, ActionContext $context): array
{
    $children = Page::where('parent_id', $context->page->id)
        ->where('is_published', true)
        ->orderBy('order')
        ->get();

    return ['children' => $children];
}`,ie=`// Использование глобальных полей
public function handle(array $params, ActionContext $context): array
{
    $phone = $context->global['phone'] ?? '';
    $email = $context->global['email'] ?? '';
    $address = $context->global['address'] ?? '';

    return compact('phone', 'email', 'address');
}`,ce=`{{-- В Blade-шаблоне блока данные доступны через $actions --}}
@foreach ($actions['my-action']['items'] as $item)
  <a href="{{ $item->full_url }}">{{ $item->title }}</a>
@endforeach

{{-- Пагинация --}}
@if (isset($actions['my-action']['paginator']))
  <x-cms::pagination :paginator="$actions['my-action']['paginator']" />
@endif`,Rt={__name:"ActionDocumentation",setup(P){const i=k([!0,!1,!1,!1,!1,!1,!1,!1]),l=k(null);async function r(v,t){try{await navigator.clipboard.writeText(t),l.value=v,setTimeout(()=>{l.value=null},2e3)}catch{const a=document.createElement("textarea");a.value=t,document.body.appendChild(a),a.select(),document.execCommand("copy"),document.body.removeChild(a),l.value=v,setTimeout(()=>{l.value=null},2e3)}}const h=[{type:"text",desc:"Текстовое поле"},{type:"number",desc:"Числовое поле"},{type:"select",desc:"Выпадающий список (options: массив или строка-источник)"},{type:"checkbox",desc:"Чекбокс (true/false)"},{type:"textarea",desc:"Многострочный текст"}],y=[{source:"page_types",desc:"Типы страниц"},{source:"languages",desc:"Языки сайта"},{source:"blocks",desc:"Блоки"}],m=[{model:"Page",ns:"Templite\\Cms\\Models\\Page",desc:"Страницы"},{model:"Block",ns:"Templite\\Cms\\Models\\Block",desc:"Блоки"},{model:"Action",ns:"Templite\\Cms\\Models\\Action",desc:"Действия"},{model:"Language",ns:"Templite\\Cms\\Models\\Language",desc:"Языки"},{model:"City",ns:"Templite\\Cms\\Models\\City",desc:"Города"},{model:"PageType",ns:"Templite\\Cms\\Models\\PageType",desc:"Типы страниц"},{model:"GlobalField",ns:"Templite\\Cms\\Models\\GlobalField",desc:"Глобальные поля"},{model:"Component",ns:"Templite\\Cms\\Models\\Component",desc:"Компоненты"}];return(v,t)=>(c(),w("div",nt,[x(V,{expanded:i.value[0],"onUpdate:expanded":t[1]||(t[1]=a=>i.value[0]=a)},{trigger:b(()=>[...t[20]||(t[20]=[s("Структура и источники",-1)])]),default:b(()=>[t[21]||(t[21]=e("p",null,"Action — PHP-класс с бизнес-логикой, привязываемый к блокам. Возвращает данные для Blade-шаблона.",-1)),e("div",st,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(X))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[0]||(t[0]=a=>r("structure",X))},[l.value==="structure"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])]),t[22]||(t[22]=e("div",null,[e("p",{class:"docs-panel__sub-label"},"Три источника (приоритет):"),e("ol",{class:"docs-panel__ordered-list"},[e("li",null,[e("code",{class:"docs-panel__inline-code"},"app/Actions/"),s(" — код разработчика (высший)")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"storage/cms/actions/"),s(" — из админки (средний)")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"vendor/"),s(" — из пакета (низший)")])])],-1))]),_:1},8,["expanded"]),x(V,{expanded:i.value[1],"onUpdate:expanded":t[3]||(t[3]=a=>i.value[1]=a)},{trigger:b(()=>[...t[23]||(t[23]=[s("Контракт (интерфейс)",-1)])]),default:b(()=>[t[24]||(t[24]=e("p",null,[s("Каждый Action обязан реализовать "),e("code",{class:"docs-panel__inline-code"},"BlockActionInterface"),s(":")],-1)),t[25]||(t[25]=e("table",{class:"docs-panel__table"},[e("thead",null,[e("tr",{class:"docs-panel__table-header-row"},[e("th",{class:"docs-panel__table-th"},"Метод"),e("th",{class:"docs-panel__table-th"},"Назначение")])]),e("tbody",{class:"docs-panel__table-tbody"},[e("tr",null,[e("td",{class:"docs-panel__table-td"},[e("code",{class:"docs-panel__inline-code"},"params()")]),e("td",{class:"docs-panel__table-td"},"Входные параметры (UI формы в админке при привязке к блоку)")]),e("tr",null,[e("td",{class:"docs-panel__table-td"},[e("code",{class:"docs-panel__inline-code"},"returns()")]),e("td",{class:"docs-panel__table-td"},"Описание возвращаемых данных (подсказки в редакторе)")]),e("tr",null,[e("td",{class:"docs-panel__table-td"},[e("code",{class:"docs-panel__inline-code"},"csrfEnabled()")]),e("td",{class:"docs-panel__table-td"},[s("CSRF при HTTP-вызове: "),e("code",{class:"docs-panel__inline-code"},"true"),s(" — требует _token, "),e("code",{class:"docs-panel__inline-code"},"false"),s(" — без CSRF")])]),e("tr",null,[e("td",{class:"docs-panel__table-td"},[e("code",{class:"docs-panel__inline-code"},"handle()")]),e("td",{class:"docs-panel__table-td"},"Основная логика (запросы, обработка, возврат данных)")])])],-1)),e("div",it,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(Y))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[2]||(t[2]=a=>r("contract",Y))},[l.value==="contract"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])])]),_:1},8,["expanded"]),x(V,{expanded:i.value[2],"onUpdate:expanded":t[7]||(t[7]=a=>i.value[2]=a)},{trigger:b(()=>[...t[26]||(t[26]=[s("ActionContext",-1)])]),default:b(()=>[t[27]||(t[27]=e("p",null,[s("Контекст передаётся вторым аргументом в "),e("code",{class:"docs-panel__inline-code"},"handle()"),s(":")],-1)),e("div",ct,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(Z))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[4]||(t[4]=a=>r("context",Z))},[l.value==="context"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])]),t[28]||(t[28]=e("p",{class:"docs-panel__sub-label"},"Свойства страницы:",-1)),e("div",dt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(ee))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[5]||(t[5]=a=>r("ctx-page",ee))},[l.value==="ctx-page"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])]),t[29]||(t[29]=e("p",{class:"docs-panel__sub-label"},"HTTP-запрос:",-1)),e("div",rt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(te))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[6]||(t[6]=a=>r("ctx-request",te))},[l.value==="ctx-request"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])])]),_:1},8,["expanded"]),x(V,{expanded:i.value[3],"onUpdate:expanded":t[9]||(t[9]=a=>i.value[3]=a)},{trigger:b(()=>[...t[30]||(t[30]=[s("Параметры (params)",-1)])]),default:b(()=>[t[32]||(t[32]=e("p",null,[s("Метод "),e("code",{class:"docs-panel__inline-code"},"params()"),s(" определяет поля настройки при привязке к блоку:")],-1)),e("table",pt,[t[31]||(t[31]=e("thead",null,[e("tr",{class:"docs-panel__table-header-row"},[e("th",{class:"docs-panel__table-th"},"Тип"),e("th",{class:"docs-panel__table-th"},"Описание")])],-1)),e("tbody",ut,[(c(),w(B,null,E(h,a=>e("tr",{key:a.type},[e("td",_t,[e("code",mt,f(a.type),1)]),e("td",yt,f(a.desc),1)])),64))])]),t[33]||(t[33]=e("p",{class:"docs-panel__sub-label"},"Источники для select:",-1)),e("table",gt,[e("tbody",ft,[(c(),w(B,null,E(y,a=>e("tr",{key:a.source},[e("td",bt,[e("code",vt,"'"+f(a.source)+"'",1)]),e("td",xt,f(a.desc),1)])),64))])]),t[34]||(t[34]=e("p",{class:"docs-panel__sub-label"},"Ключи параметра:",-1)),t[35]||(t[35]=e("ul",{class:"docs-panel__list"},[e("li",null,[e("code",{class:"docs-panel__inline-code"},"type"),s(" — тип поля (обязательный)")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"label"),s(" — название в админке")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"default"),s(" — значение по умолчанию")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"required"),s(" — обязательность (true/false)")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"options"),s(" — варианты для select")])],-1)),e("div",$t,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(le))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[8]||(t[8]=a=>r("params",le))},[l.value==="params"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])])]),_:1},8,["expanded"]),x(V,{expanded:i.value[4],"onUpdate:expanded":t[11]||(t[11]=a=>i.value[4]=a)},{trigger:b(()=>[...t[36]||(t[36]=[s("Возвращаемые данные (returns)",-1)])]),default:b(()=>[t[37]||(t[37]=e("p",null,[s("Метод "),e("code",{class:"docs-panel__inline-code"},"returns()"),s(" описывает данные для подсказок в редакторе блока:")],-1)),e("div",kt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(oe))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[10]||(t[10]=a=>r("returns",oe))},[l.value==="returns"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])])]),_:1},8,["expanded"]),x(V,{expanded:i.value[5],"onUpdate:expanded":t[16]||(t[16]=a=>i.value[5]=a)},{trigger:b(()=>[...t[38]||(t[38]=[s("Примеры handle()",-1)])]),default:b(()=>[t[39]||(t[39]=e("p",{class:"docs-panel__sub-label"},"Список страниц с фильтрацией:",-1)),e("div",ht,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(ae))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[12]||(t[12]=a=>r("handle",ae))},[l.value==="handle"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])]),t[40]||(t[40]=e("p",{class:"docs-panel__sub-label"},"Пагинация:",-1)),e("div",Ct,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(ne))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[13]||(t[13]=a=>r("handle-pag",ne))},[l.value==="handle-pag"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])]),t[41]||(t[41]=e("p",{class:"docs-panel__sub-label"},"Дочерние страницы:",-1)),e("div",wt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(se))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[14]||(t[14]=a=>r("handle-children",se))},[l.value==="handle-children"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])]),t[42]||(t[42]=e("p",{class:"docs-panel__sub-label"},"Глобальные поля:",-1)),e("div",Pt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(ie))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[15]||(t[15]=a=>r("handle-globals",ie))},[l.value==="handle-globals"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])])]),_:1},8,["expanded"]),x(V,{expanded:i.value[6],"onUpdate:expanded":t[18]||(t[18]=a=>i.value[6]=a)},{trigger:b(()=>[...t[43]||(t[43]=[s("Использование в Blade",-1)])]),default:b(()=>[t[44]||(t[44]=e("p",null,[s("Данные Action доступны в шаблоне блока через "),e("code",{class:"docs-panel__inline-code"},"$actions['slug']"),s(":")],-1)),e("div",St,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,f(ce))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[17]||(t[17]=a=>r("blade",ce))},[l.value==="blade"?(c(),_(o(S),{key:0,class:"icon-3h icon-check-green"})):(c(),_(o(T),{key:1,class:"icon-3h"}))])]),t[45]||(t[45]=e("ul",{class:"docs-panel__list"},[e("li",null,[e("code",{class:"docs-panel__inline-code"},"$actions"),s(" — массив всех привязанных Actions")]),e("li",null,[s("Ключ — slug действия (например "),e("code",{class:"docs-panel__inline-code"},"'my-action'"),s(")")]),e("li",null,[s("Значение — массив, возвращённый из "),e("code",{class:"docs-panel__inline-code"},"handle()")])],-1))]),_:1},8,["expanded"]),x(V,{expanded:i.value[7],"onUpdate:expanded":t[19]||(t[19]=a=>i.value[7]=a)},{trigger:b(()=>[...t[46]||(t[46]=[s("Разрешённые модели",-1)])]),default:b(()=>[t[48]||(t[48]=e("p",null,"Код Actions проходит серверную валидацию. Разрешены только модели из whitelist:",-1)),e("table",Tt,[t[47]||(t[47]=e("thead",null,[e("tr",{class:"docs-panel__table-header-row"},[e("th",{class:"docs-panel__table-th"},"Модель"),e("th",{class:"docs-panel__table-th"},"Namespace"),e("th",{class:"docs-panel__table-th"},"Описание")])],-1)),e("tbody",At,[(c(),w(B,null,E(m,a=>e("tr",{key:a.model},[e("td",qt,[e("code",Dt,f(a.model),1)]),e("td",Mt,f(a.ns),1),e("td",Vt,f(a.desc),1)])),64))])]),t[49]||(t[49]=e("ul",{class:"docs-panel__list"},[e("li",null,[s("Модели из "),e("code",{class:"docs-panel__inline-code"},"App\\Models\\*"),s(" тоже разрешены")]),e("li",null,[s("Стандартные классы: "),e("code",{class:"docs-panel__inline-code"},"stdClass"),s(", "),e("code",{class:"docs-panel__inline-code"},"Carbon"),s(", "),e("code",{class:"docs-panel__inline-code"},"Collection")]),e("li",null,[s("Laravel-хелперы: "),e("code",{class:"docs-panel__inline-code"},"collect()"),s(", "),e("code",{class:"docs-panel__inline-code"},"optional()"),s(", "),e("code",{class:"docs-panel__inline-code"},"cache()"),s(", "),e("code",{class:"docs-panel__inline-code"},"now()")]),e("li",null,[s("Запрещены: "),e("code",{class:"docs-panel__inline-code docs-panel__inline-code--danger"},"eval"),s(", "),e("code",{class:"docs-panel__inline-code docs-panel__inline-code--danger"},"exec"),s(", "),e("code",{class:"docs-panel__inline-code docs-panel__inline-code--danger"},"system"),s(", "),e("code",{class:"docs-panel__inline-code docs-panel__inline-code--danger"},"file_get_contents"),s(", "),e("code",{class:"docs-panel__inline-code docs-panel__inline-code--danger"},"include/require")])],-1))]),_:1},8,["expanded"])]))}};function Ut({getParams:P,getGlobalFields:i}){const l=[{label:"title",detail:"string",info:"Заголовок страницы"},{label:"url",detail:"string",info:"URL страницы"},{label:"slug",detail:"string",info:"Slug страницы"},{label:"seo_data",detail:"array",info:"SEO-данные"},{label:"img",detail:"File|null",info:"Изображение"},{label:"status",detail:"string",info:"Статус (active/draft)"},{label:"views",detail:"int",info:"Количество просмотров"},{label:"parent",detail:"Page|null",info:"Родительская страница"},{label:"children",detail:"Collection",info:"Дочерние страницы"}],r=[{label:"get('key')",detail:"mixed",info:"Получить параметр"},{label:"input('key')",detail:"mixed",info:"Получить input"},{label:"all()",detail:"array",info:"Все данные запроса"},{label:"has('key')",detail:"bool",info:"Проверка наличия"},{label:"query('key')",detail:"mixed",info:"Query-параметр"},{label:"post('key')",detail:"mixed",info:"POST-параметр"},{label:"boolean('key')",detail:"bool",info:"Boolean-параметр"}],h=[{label:"page",detail:"Page",info:"Текущая страница"},{label:"request",detail:"Request",info:"HTTP-запрос"},{label:"global",detail:"array",info:"Глобальные поля"},{label:"blockData",detail:"array",info:"Данные полей блока"}];return y=>{const m=y.state.doc.lineAt(y.pos),v=m.text.slice(0,y.pos-m.from),t=v.match(/\$context->request->(\w*)$/);if(t){const d=t[1];return{from:y.pos-d.length,options:r.map(p=>({label:p.label,type:"method",detail:p.detail,info:p.info}))}}const a=v.match(/\$context->page->(\w*)$/);if(a){const d=a[1];return{from:y.pos-d.length,options:l.map(p=>({label:p.label,type:"property",detail:p.detail,info:p.info}))}}const D=v.match(/\$context->global\['([^']*)$/);if(D){const d=D[1],p=i();return{from:y.pos-d.length,options:p.map(g=>({label:g.key,type:"variable",detail:g.type||"mixed",info:g.name||g.key,apply:g.key+"']"}))}}const A=v.match(/\$context->(\w*)$/);if(A){const d=A[1];return{from:y.pos-d.length,options:h.map(p=>({label:p.label,type:"property",detail:p.detail,info:p.info}))}}const $=v.match(/\$params\['([^']*)$/);if($){const d=$[1],p=P();return{from:y.pos-d.length,options:p.filter(g=>g.key.trim()).map(g=>({label:g.key,type:"variable",detail:g.type||"mixed",info:g.description||g.key,apply:g.key+"']"}))}}const n=v.match(/\$(\w*)$/);if(n){const d=n[1];return{from:y.pos-d.length,options:[{label:"params",type:"variable",detail:"array",info:"Входные параметры action"},{label:"context",type:"variable",detail:"ActionContext",info:"Контекст выполнения (page, request, global, blockData)"}]}}return null}}const Bt={class:"action-workspace__editor"},Yt={__name:"Edit",props:{action:{type:Object,default:null},defaultCode:{type:String,default:""},globalFieldDefinitions:{type:Array,default:()=>[]}},setup(P){const i=P,l=W(),{confirm:r}=qe(),{cmsUrl:h}=Me();function y($){($.ctrlKey||$.metaKey)&&$.key==="s"&&($.preventDefault(),l.isSaving||l.saveAction())}Ue(()=>{l.init(i),window.addEventListener("keydown",y)}),Be(()=>{window.removeEventListener("keydown",y),l.$reset()}),Ie(R(()=>l.isDirty));const m=[{key:"main",label:"Основные",icon:We},{key:"vars",label:"Переменные",icon:ze}];async function v(){await r({title:"Удалить Action?",message:`"${l.form.name}" будет удалён.`,variant:"danger",confirmText:"Удалить"})&&l.deleteAction()}const t=R(()=>({main:"Основные",vars:"Переменные"})[l.activeSidePanel]||""),a=R(()=>l.activeSidePanel?`${l.sidePanelWidth+4}px`:"0px"),D=R(()=>l.isDocsPanelOpen?`${l.docsPanelWidth+4}px`:"0px"),A=Ut({getParams:()=>l.form.params,getGlobalFields:()=>l.globalFieldDefinitions});return($,n)=>(c(),w("div",null,[x(ke,{"back-url":o(h)("/actions"),title:o(l).form.name||"Action",tabs:m,"active-tab":o(l).activeSidePanel,"is-dirty":o(l).isDirty,"is-saving":o(l).isSaving,onTabChange:n[1]||(n[1]=d=>o(l).toggleSidePanel(d)),onSave:n[2]||(n[2]=d=>o(l).saveAction()),onDelete:v},{controls:b(()=>[e("button",{type:"button",class:I(["submenu__toggle",o(l).isDocsPanelOpen?"submenu__toggle--active":"submenu__toggle--inactive"]),title:"Документация",onClick:n[0]||(n[0]=d=>o(l).toggleDocsPanel())},[x(o(Ae),{class:"icon-4"})],2)]),_:1},8,["back-url","title","active-tab","is-dirty","is-saving"]),x(J,{open:!!o(l).activeSidePanel,title:t.value,width:o(l).sidePanelWidth,side:"left",onClose:n[3]||(n[3]=d=>o(l).closeSidePanel()),onResize:n[4]||(n[4]=d=>o(l).setSidePanelWidth(d)),onResizeStart:n[5]||(n[5]=d=>o(l).isSidePanelResizing=!0),onResizeEnd:n[6]||(n[6]=d=>o(l).isSidePanelResizing=!1)},{default:b(()=>[o(l).activeSidePanel==="main"?(c(),_(Je,{key:0})):U("",!0),o(l).activeSidePanel==="vars"?(c(),_(at,{key:1})):U("",!0)]),_:1},8,["open","title","width"]),x(J,{open:o(l).isDocsPanelOpen,title:"Документация",width:o(l).docsPanelWidth,side:"right",onClose:n[7]||(n[7]=d=>o(l).closeDocsPanel()),onResize:n[8]||(n[8]=d=>o(l).setDocsPanelWidth(d)),onResizeStart:n[9]||(n[9]=d=>o(l).isDocsPanelResizing=!0),onResizeEnd:n[10]||(n[10]=d=>o(l).isDocsPanelResizing=!1)},{default:b(()=>[x(Rt)]),_:1},8,["open","width"]),e("div",{class:I(["action-workspace",!(o(l).isSidePanelResizing||o(l).isDocsPanelResizing)&&"action-workspace--smooth"]),style:Ee({left:a.value,right:D.value})},[e("div",Bt,[x(he,{modelValue:o(l).form.code,"onUpdate:modelValue":n[11]||(n[11]=d=>o(l).form.code=d),language:"php","full-height":"","completion-source":o(A)},null,8,["modelValue","completion-source"])])],6)]))}};export{Yt as _};
