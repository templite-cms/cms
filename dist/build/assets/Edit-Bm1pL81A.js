import{_ as he,a as J}from"./EditSidePanel-dsOdTbWM.js";import{_ as we}from"./CodeEditor-tKLhHSan.js";import{_ as j}from"./TextField-CW1Sr1kl.js";import{_ as Ce}from"./CheckboxField-60acDb3G.js";import{_ as Pe}from"./SlugField-DsJdEW54.js";import{_ as Se}from"./MediaPicker-D7Ntx6Hc.js";import{d as Te,I as qe}from"./mountPage-oAGecqE4.js";import{u as Ae}from"./useApi-B9Yvr7I0.js";import{b as De,c as Ee}from"./aiBridge-aqk1dWQM.js";import{u as Me,a as Ve}from"./DateField.vue_vue_type_style_index_0_lang-AWnM9HLT.js";import{g as X,u as Re}from"./useCmsUrl-BMH6Mdh_.js";import{r as w,w as Ue,v as c,x as C,p as x,z as o,l as e,s as L,k as y,A as Be,D as R,B as g,f as V,F as B,y as F,q as $,E as i,K as Fe,L as ze,V as Ie}from"./x-CuOWXDIc.js";import{C as P}from"./check-D7sgkfv8.js";import{C as S,G as Le,a as Oe}from"./lucide-vue-next-CftrwK51.js";import{_ as M}from"./Accordion-BbATSDTQ.js";import{u as We}from"./useUnsavedChanges-CAwcoqX1.js";import{S as He}from"./zap-ZF9n3rfB.js";const O=Te("actionEditor",()=>{const v=w(null),a=w([]),l=w({name:"",slug:"",description:"",code:"",screen:null,allow_http:!1}),r=w({}),k=w(null),u=w(null),p=w(320),b=w(!1),t=w(!1),n=w(360),D=w(!1),T=w(!1),q=w(!1);let _=null;function d(m){var h,E,W,H,G,N,K,Q;v.value=m.action,a.value=m.globalFieldDefinitions||[],l.value={name:((h=m.action)==null?void 0:h.name)||"",slug:((E=m.action)==null?void 0:E.slug)||"",description:((W=m.action)==null?void 0:W.description)||"",code:((H=m.action)==null?void 0:H.code)||m.defaultCode||"",screen:((N=(G=m.action)==null?void 0:G.screen)==null?void 0:N.id)??((K=m.action)==null?void 0:K.screen)??null,allow_http:((Q=m.action)==null?void 0:Q.allow_http)??!1},k.value=null,u.value=null,T.value=!1,q.value=!1,r.value={},s(),_&&_();const A=JSON.stringify(l.value);_=Ue(l,()=>{q.value=JSON.stringify(l.value)!==A},{deep:!0})}function s(){try{const m=localStorage.getItem("action_editor_side_panel_width");if(m){const h=parseInt(m,10);h>=250&&h<=800&&(p.value=h)}const A=localStorage.getItem("action_editor_docs_panel_width");if(A){const h=parseInt(A,10);h>=250&&h<=800&&(n.value=h)}}catch{}}function f(){try{localStorage.setItem("action_editor_side_panel_width",String(p.value)),localStorage.setItem("action_editor_docs_panel_width",String(n.value))}catch{}}function z(m){k.value=k.value===m?null:m}function re(){k.value=null}function pe(m){u.value=u.value===m?null:m}function ue(){u.value=null}function _e(m){p.value=Math.max(250,Math.min(m,window.innerWidth*.5)),f()}function me(){t.value=!t.value}function ye(){t.value=!1}function fe(m){n.value=Math.max(250,Math.min(m,window.innerWidth*.4)),f()}const{post:ge,put:be,del:ve}=Ae(),I=Me();async function $e(){var m;T.value=!0,r.value={};try{const A={name:l.value.name,slug:l.value.slug,description:l.value.description,code:l.value.code,screen:l.value.screen,allow_http:l.value.allow_http};if((m=v.value)!=null&&m.id){const h=De();await be(`/api/cms/actions/${v.value.id}`,A,h.options),h.done()}else{const h=await ge("/api/cms/actions",A),E=(h==null?void 0:h.data)||h;if(E!=null&&E.id){window.location.href=X(`/actions/${E.id}/edit`);return}}q.value=!1,I.success("Action сохранён")}catch(A){A.errors&&(r.value=A.errors,["name","slug"].some(E=>r.value[E])&&!u.value&&(u.value="main")),I.error("Ошибка при сохранении")}finally{T.value=!1}}async function xe(){var m;if((m=v.value)!=null&&m.id)try{await ve(`/api/cms/actions/${v.value.id}`),I.success("Action удалён"),window.location.href=X("/actions")}catch{I.error("Ошибка удаления")}}function ke(){_&&(_(),_=null),v.value=null,a.value=[],l.value={name:"",slug:"",description:"",code:"",screen:null,allow_http:!1},r.value={},k.value=null,u.value=null,p.value=320,b.value=!1,t.value=!1,n.value=360,D.value=!1,T.value=!1,q.value=!1}return{$reset:ke,action:v,globalFieldDefinitions:a,form:l,errors:r,activeMegaMenu:k,activeSidePanel:u,sidePanelWidth:p,isSidePanelResizing:b,isDocsPanelOpen:t,docsPanelWidth:n,isDocsPanelResizing:D,isSaving:T,isDirty:q,init:d,toggleMegaMenu:z,closeMegaMenu:re,toggleSidePanel:pe,closeSidePanel:ue,setSidePanelWidth:_e,toggleDocsPanel:me,closeDocsPanel:ye,setDocsPanelWidth:fe,saveAction:$e,deleteAction:xe}}),Ge={class:"action-main-fields"},Ne={key:0,class:"action-main-fields__endpoint"},Ke={class:"action-main-fields__endpoint-row"},Qe=["value"],Je={key:1,class:"action-main-fields__source"},je={class:"action-main-fields__source-badge"},Xe={__name:"ActionMainFields",setup(v){const a=O(),l=V(()=>a.form.slug?`/action/${a.form.slug}`:""),r=w(!1);function k(){if(!l.value)return;const u=window.location.origin+l.value;navigator.clipboard.writeText(u).then(()=>{r.value=!0,setTimeout(()=>{r.value=!1},1500)})}return(u,p)=>{var b;return c(),C("div",Ge,[x(j,{modelValue:o(a).form.name,"onUpdate:modelValue":p[0]||(p[0]=t=>o(a).form.name=t),label:"Название",required:"",error:o(a).errors.name,placeholder:"get_latest_posts"},null,8,["modelValue","error"]),x(Pe,{modelValue:o(a).form.slug,"onUpdate:modelValue":p[1]||(p[1]=t=>o(a).form.slug=t),"source-value":o(a).form.name,error:o(a).errors.slug},null,8,["modelValue","source-value","error"]),x(j,{modelValue:o(a).form.description,"onUpdate:modelValue":p[2]||(p[2]=t=>o(a).form.description=t),label:"Описание",type:"textarea",rows:3,placeholder:"Описание того, что делает этот action..."},null,8,["modelValue"]),x(Se,{modelValue:o(a).form.screen,"onUpdate:modelValue":p[3]||(p[3]=t=>o(a).form.screen=t),label:"Скриншот",type:"image"},null,8,["modelValue"]),x(Ce,{modelValue:o(a).form.allow_http,"onUpdate:modelValue":p[4]||(p[4]=t=>o(a).form.allow_http=t),label:"Доступен по HTTP"},null,8,["modelValue"]),o(a).form.allow_http&&o(a).form.slug?(c(),C("div",Ne,[p[5]||(p[5]=e("label",{class:"action-main-fields__endpoint-label"},"Эндпоинт",-1)),e("div",Ke,[e("input",{type:"text",value:l.value,readonly:"",class:"action-main-fields__endpoint-input"},null,8,Qe),e("button",{type:"button",class:L(["action-main-fields__endpoint-copy",r.value?"action-main-fields__endpoint-copy--success":"action-main-fields__endpoint-copy--idle"]),title:"Скопировать URL",onClick:k},[(c(),y(Be(r.value?o(P):o(S)),{class:"icon-4"}))],2)])])):R("",!0),(b=o(a).action)!=null&&b.source?(c(),C("div",Je,[p[6]||(p[6]=e("span",{class:"action-main-fields__source-label"},"Источник:",-1)),e("span",je,g(o(a).action.source),1)])):R("",!0)])}}},Ye={class:"variables-panel"},Ze={key:0,class:"variables-panel__section-count"},et={class:"variables-panel__section-body"},tt={key:0,class:"variables-panel__items"},lt=["onClick","onDragstart"],ot={class:"list-item-min__code"},nt={key:0,class:"list-item-min__type"},at={class:"list-item-min__name"},st={class:"list-item-min__action"},it={__name:"ActionVariablesPanel",setup(v){const a=O(),l=w({0:!0,1:!0,2:!1,3:!1,4:!1}),r=w(null);async function k(_,d){try{await navigator.clipboard.writeText(d),r.value=_,setTimeout(()=>{r.value=null},2e3)}catch{const s=document.createElement("textarea");s.value=d,document.body.appendChild(s),s.select(),document.execCommand("copy"),document.body.removeChild(s),r.value=_,setTimeout(()=>{r.value=null},2e3)}}function u(_,d){_.dataTransfer.setData("text/plain",d),_.dataTransfer.setData("application/x-action-variable",""),_.dataTransfer.effectAllowed="copy"}const p=[{code:"$context->page",desc:"Объект Page",type:"Page"},{code:"$context->request",desc:"HTTP-запрос",type:"Request"},{code:"$context->global",desc:"Глобальные поля",type:"array"},{code:"$context->blockData",desc:"Данные полей блока",type:"array"},{code:"$result",desc:"Результат (возвращаемое значение)",type:"mixed"}],b=[{code:"$context->page->title",desc:"Заголовок страницы",type:"string"},{code:"$context->page->url",desc:"URL страницы",type:"string"},{code:"$context->page->slug",desc:"Slug страницы",type:"string"},{code:"$context->page->seo_data",desc:"SEO-данные",type:"array"},{code:"$context->page->img",desc:"Изображение страницы",type:"File|null"},{code:"$context->page->status",desc:"Статус публикации",type:"string"},{code:"$context->page->views",desc:"Количество просмотров",type:"int"},{code:"$context->page->parent",desc:"Родительская страница",type:"Page|null"},{code:"$context->page->children",desc:"Дочерние страницы",type:"Collection"}],t=[{code:"$context->request->get('key')",desc:"Получить параметр",type:"mixed"},{code:"$context->request->input('key')",desc:"Получить input",type:"mixed"},{code:"$context->request->all()",desc:"Все параметры",type:"array"},{code:"$context->request->has('key')",desc:"Проверка наличия",type:"bool"},{code:"$context->request->query('key')",desc:"Query-параметр",type:"mixed"},{code:"$context->request->boolean('key')",desc:"Boolean-параметр",type:"bool"}],n=V(()=>a.globalFieldDefinitions.map(_=>({code:`$context->global['${_.key}']`,desc:_.name||_.key,type:_.type||"mixed"}))),D=[{code:"$context->blockData['fieldKey']",desc:"Значение поля блока",type:"mixed"}],T=V(()=>[{index:0,title:"Контекст",items:p,prefix:"ctx",alwaysShow:!0},{index:1,title:"Страница",items:b,prefix:"page",alwaysShow:!0},{index:2,title:"Запрос",items:t,prefix:"req",alwaysShow:!0},{index:3,title:"Глобальные поля",items:n.value,prefix:"g"},{index:4,title:"Данные блока",items:D,prefix:"bd",alwaysShow:!0}]),q=V(()=>T.value.filter(_=>_.alwaysShow||_.items.length>0));return(_,d)=>(c(),C("div",Ye,[(c(!0),C(B,null,F(q.value,s=>(c(),y(M,{key:s.index,expanded:l.value[s.index],"onUpdate:expanded":f=>l.value[s.index]=f},{trigger:$(()=>[i(g(s.title)+" ",1),s.items.length?(c(),C("span",Ze,"("+g(s.items.length)+")",1)):R("",!0)]),default:$(()=>[e("div",et,[s.items.length?(c(),C("div",tt,[(c(!0),C(B,null,F(s.items,f=>(c(),C("div",{key:f.code,draggable:"true",class:"list-item-min",onClick:z=>k(s.prefix+"-"+f.code,f.code),onDragstart:z=>u(z,f.code)},[x(o(Le),{class:"list-item-min__drag"}),e("code",ot,g(f.code),1),f.type?(c(),C("span",nt,g(f.type),1)):R("",!0),e("span",at,g(f.desc),1),e("span",st,[r.value===s.prefix+"-"+f.code?(c(),y(o(P),{key:0,class:"list-item-min__action-icon--success"})):(c(),y(o(S),{key:1,class:"list-item-min__action-icon"}))])],40,lt))),128))])):R("",!0)])]),_:2},1032,["expanded","onUpdate:expanded"]))),128))]))}},ct={class:"docs-panel"},dt={class:"docs-panel__code-block"},rt={class:"docs-panel__code-block"},pt={class:"docs-panel__code-block"},ut={class:"docs-panel__code-block"},_t={class:"docs-panel__code-block"},mt={class:"docs-panel__table"},yt={class:"docs-panel__table-tbody"},ft={class:"docs-panel__table-td"},gt={class:"docs-panel__inline-code"},bt={class:"docs-panel__table-td"},vt={class:"docs-panel__table"},$t={class:"docs-panel__table-tbody"},xt={class:"docs-panel__table-td"},kt={class:"docs-panel__inline-code"},ht={class:"docs-panel__table-td"},wt={class:"docs-panel__code-block"},Ct={class:"docs-panel__code-block"},Pt={class:"docs-panel__code-block"},St={class:"docs-panel__code-block"},Tt={class:"docs-panel__code-block"},qt={class:"docs-panel__code-block"},At={class:"docs-panel__code-block"},Dt={class:"docs-panel__table"},Et={class:"docs-panel__table-tbody"},Mt={class:"docs-panel__table-td"},Vt={class:"docs-panel__inline-code"},Rt={class:"docs-panel__table-td docs-panel__table-td--nowrap docs-panel__table-td--muted",style:{"font-family":"var(--font-mono)","font-size":"10px"}},Ut={class:"docs-panel__table-td"},Y=`storage/cms/actions/
  my-action.php         -- PHP-класс действия

app/Actions/
  MyAction.php          -- код разработчика (приоритет выше)`,Z=`<?php

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
}`,ee=`// ActionContext -- объект контекста, передаётся вторым аргументом в handle()

$context->page        // Page      -- текущая страница
$context->request     // Request   -- HTTP-запрос
$context->global      // array     -- глобальные поля (key => value)
$context->blockData   // array     -- данные текущего блока (значения полей)`,te=`// Свойства $context->page (модель Page)
$context->page->id
$context->page->title
$context->page->slug
$context->page->full_url       // полный путь (/catalog/item)
$context->page->parent_id
$context->page->page_type_id
$context->page->is_published
$context->page->seo_data['title'] ?? null        // SEO title
$context->page->seo_data['description'] ?? null  // SEO description
$context->page->created_at
$context->page->updated_at`,le=`// Свойства $context->request (HTTP-запрос)
$context->request->query('page')      // GET-параметр ?page=2
$context->request->query('search')    // GET-параметр ?search=...
$context->request->all()              // все параметры
$context->request->ip()               // IP клиента
$context->request->url()              // текущий URL`,oe=`public function params(): array
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
}`,ne=`public function returns(): array
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
}`,se=`// Пагинация через query-параметры
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
}`,ie=`// Дочерние страницы текущей
public function handle(array $params, ActionContext $context): array
{
    $children = Page::where('parent_id', $context->page->id)
        ->where('is_published', true)
        ->orderBy('order')
        ->get();

    return ['children' => $children];
}`,ce=`// Использование глобальных полей
public function handle(array $params, ActionContext $context): array
{
    $phone = $context->global['phone'] ?? '';
    $email = $context->global['email'] ?? '';
    $address = $context->global['address'] ?? '';

    return compact('phone', 'email', 'address');
}`,de=`{{-- В Blade-шаблоне блока данные доступны через $actions --}}
@foreach ($actions['my-action']['items'] as $item)
  <a href="{{ $item->full_url }}">{{ $item->title }}</a>
@endforeach

{{-- Пагинация --}}
@if (isset($actions['my-action']['paginator']))
  <x-cms::pagination :paginator="$actions['my-action']['paginator']" />
@endif`,Bt={__name:"ActionDocumentation",setup(v){const a=w([!0,!1,!1,!1,!1,!1,!1,!1]),l=w(null);async function r(b,t){try{await navigator.clipboard.writeText(t),l.value=b,setTimeout(()=>{l.value=null},2e3)}catch{const n=document.createElement("textarea");n.value=t,document.body.appendChild(n),n.select(),document.execCommand("copy"),document.body.removeChild(n),l.value=b,setTimeout(()=>{l.value=null},2e3)}}const k=[{type:"text",desc:"Текстовое поле"},{type:"number",desc:"Числовое поле"},{type:"select",desc:"Выпадающий список (options: массив или строка-источник)"},{type:"checkbox",desc:"Чекбокс (true/false)"},{type:"textarea",desc:"Многострочный текст"}],u=[{source:"page_types",desc:"Типы страниц"},{source:"blocks",desc:"Блоки"}],p=[{model:"Page",ns:"Templite\\Cms\\Models\\Page",desc:"Страницы"},{model:"Block",ns:"Templite\\Cms\\Models\\Block",desc:"Блоки"},{model:"Action",ns:"Templite\\Cms\\Models\\Action",desc:"Действия"},{model:"PageType",ns:"Templite\\Cms\\Models\\PageType",desc:"Типы страниц"},{model:"GlobalField",ns:"Templite\\Cms\\Models\\GlobalField",desc:"Глобальные поля"},{model:"Component",ns:"Templite\\Cms\\Models\\Component",desc:"Компоненты"}];return(b,t)=>(c(),C("div",ct,[x(M,{expanded:a.value[0],"onUpdate:expanded":t[1]||(t[1]=n=>a.value[0]=n)},{trigger:$(()=>[...t[20]||(t[20]=[i("Структура и источники",-1)])]),default:$(()=>[t[21]||(t[21]=e("p",null,"Action — PHP-класс с бизнес-логикой, привязываемый к блокам. Возвращает данные для Blade-шаблона.",-1)),e("div",dt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(Y))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[0]||(t[0]=n=>r("structure",Y))},[l.value==="structure"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])]),t[22]||(t[22]=e("div",null,[e("p",{class:"docs-panel__sub-label"},"Три источника (приоритет):"),e("ol",{class:"docs-panel__ordered-list"},[e("li",null,[e("code",{class:"docs-panel__inline-code"},"app/Actions/"),i(" — код разработчика (высший)")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"storage/cms/actions/"),i(" — из админки (средний)")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"vendor/"),i(" — из пакета (низший)")])])],-1))]),_:1},8,["expanded"]),x(M,{expanded:a.value[1],"onUpdate:expanded":t[3]||(t[3]=n=>a.value[1]=n)},{trigger:$(()=>[...t[23]||(t[23]=[i("Контракт (интерфейс)",-1)])]),default:$(()=>[t[24]||(t[24]=e("p",null,[i("Каждый Action обязан реализовать "),e("code",{class:"docs-panel__inline-code"},"BlockActionInterface"),i(":")],-1)),t[25]||(t[25]=e("table",{class:"docs-panel__table"},[e("thead",null,[e("tr",{class:"docs-panel__table-header-row"},[e("th",{class:"docs-panel__table-th"},"Метод"),e("th",{class:"docs-panel__table-th"},"Назначение")])]),e("tbody",{class:"docs-panel__table-tbody"},[e("tr",null,[e("td",{class:"docs-panel__table-td"},[e("code",{class:"docs-panel__inline-code"},"params()")]),e("td",{class:"docs-panel__table-td"},"Входные параметры (UI формы в админке при привязке к блоку)")]),e("tr",null,[e("td",{class:"docs-panel__table-td"},[e("code",{class:"docs-panel__inline-code"},"returns()")]),e("td",{class:"docs-panel__table-td"},"Описание возвращаемых данных (подсказки в редакторе)")]),e("tr",null,[e("td",{class:"docs-panel__table-td"},[e("code",{class:"docs-panel__inline-code"},"csrfEnabled()")]),e("td",{class:"docs-panel__table-td"},[i("CSRF при HTTP-вызове: "),e("code",{class:"docs-panel__inline-code"},"true"),i(" — требует _token, "),e("code",{class:"docs-panel__inline-code"},"false"),i(" — без CSRF")])]),e("tr",null,[e("td",{class:"docs-panel__table-td"},[e("code",{class:"docs-panel__inline-code"},"handle()")]),e("td",{class:"docs-panel__table-td"},"Основная логика (запросы, обработка, возврат данных)")])])],-1)),e("div",rt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(Z))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[2]||(t[2]=n=>r("contract",Z))},[l.value==="contract"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])])]),_:1},8,["expanded"]),x(M,{expanded:a.value[2],"onUpdate:expanded":t[7]||(t[7]=n=>a.value[2]=n)},{trigger:$(()=>[...t[26]||(t[26]=[i("ActionContext",-1)])]),default:$(()=>[t[27]||(t[27]=e("p",null,[i("Контекст передаётся вторым аргументом в "),e("code",{class:"docs-panel__inline-code"},"handle()"),i(":")],-1)),e("div",pt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(ee))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[4]||(t[4]=n=>r("context",ee))},[l.value==="context"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])]),t[28]||(t[28]=e("p",{class:"docs-panel__sub-label"},"Свойства страницы:",-1)),e("div",ut,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(te))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[5]||(t[5]=n=>r("ctx-page",te))},[l.value==="ctx-page"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])]),t[29]||(t[29]=e("p",{class:"docs-panel__sub-label"},"HTTP-запрос:",-1)),e("div",_t,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(le))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[6]||(t[6]=n=>r("ctx-request",le))},[l.value==="ctx-request"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])])]),_:1},8,["expanded"]),x(M,{expanded:a.value[3],"onUpdate:expanded":t[9]||(t[9]=n=>a.value[3]=n)},{trigger:$(()=>[...t[30]||(t[30]=[i("Параметры (params)",-1)])]),default:$(()=>[t[32]||(t[32]=e("p",null,[i("Метод "),e("code",{class:"docs-panel__inline-code"},"params()"),i(" определяет поля настройки при привязке к блоку:")],-1)),e("table",mt,[t[31]||(t[31]=e("thead",null,[e("tr",{class:"docs-panel__table-header-row"},[e("th",{class:"docs-panel__table-th"},"Тип"),e("th",{class:"docs-panel__table-th"},"Описание")])],-1)),e("tbody",yt,[(c(),C(B,null,F(k,n=>e("tr",{key:n.type},[e("td",ft,[e("code",gt,g(n.type),1)]),e("td",bt,g(n.desc),1)])),64))])]),t[33]||(t[33]=e("p",{class:"docs-panel__sub-label"},"Источники для select:",-1)),e("table",vt,[e("tbody",$t,[(c(),C(B,null,F(u,n=>e("tr",{key:n.source},[e("td",xt,[e("code",kt,"'"+g(n.source)+"'",1)]),e("td",ht,g(n.desc),1)])),64))])]),t[34]||(t[34]=e("p",{class:"docs-panel__sub-label"},"Ключи параметра:",-1)),t[35]||(t[35]=e("ul",{class:"docs-panel__list"},[e("li",null,[e("code",{class:"docs-panel__inline-code"},"type"),i(" — тип поля (обязательный)")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"label"),i(" — название в админке")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"default"),i(" — значение по умолчанию")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"required"),i(" — обязательность (true/false)")]),e("li",null,[e("code",{class:"docs-panel__inline-code"},"options"),i(" — варианты для select")])],-1)),e("div",wt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(oe))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[8]||(t[8]=n=>r("params",oe))},[l.value==="params"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])])]),_:1},8,["expanded"]),x(M,{expanded:a.value[4],"onUpdate:expanded":t[11]||(t[11]=n=>a.value[4]=n)},{trigger:$(()=>[...t[36]||(t[36]=[i("Возвращаемые данные (returns)",-1)])]),default:$(()=>[t[37]||(t[37]=e("p",null,[i("Метод "),e("code",{class:"docs-panel__inline-code"},"returns()"),i(" описывает данные для подсказок в редакторе блока:")],-1)),e("div",Ct,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(ne))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[10]||(t[10]=n=>r("returns",ne))},[l.value==="returns"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])])]),_:1},8,["expanded"]),x(M,{expanded:a.value[5],"onUpdate:expanded":t[16]||(t[16]=n=>a.value[5]=n)},{trigger:$(()=>[...t[38]||(t[38]=[i("Примеры handle()",-1)])]),default:$(()=>[t[39]||(t[39]=e("p",{class:"docs-panel__sub-label"},"Список страниц с фильтрацией:",-1)),e("div",Pt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(ae))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[12]||(t[12]=n=>r("handle",ae))},[l.value==="handle"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])]),t[40]||(t[40]=e("p",{class:"docs-panel__sub-label"},"Пагинация:",-1)),e("div",St,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(se))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[13]||(t[13]=n=>r("handle-pag",se))},[l.value==="handle-pag"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])]),t[41]||(t[41]=e("p",{class:"docs-panel__sub-label"},"Дочерние страницы:",-1)),e("div",Tt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(ie))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[14]||(t[14]=n=>r("handle-children",ie))},[l.value==="handle-children"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])]),t[42]||(t[42]=e("p",{class:"docs-panel__sub-label"},"Глобальные поля:",-1)),e("div",qt,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(ce))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[15]||(t[15]=n=>r("handle-globals",ce))},[l.value==="handle-globals"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])])]),_:1},8,["expanded"]),x(M,{expanded:a.value[6],"onUpdate:expanded":t[18]||(t[18]=n=>a.value[6]=n)},{trigger:$(()=>[...t[43]||(t[43]=[i("Использование в Blade",-1)])]),default:$(()=>[t[44]||(t[44]=e("p",null,[i("Данные Action доступны в шаблоне блока через "),e("code",{class:"docs-panel__inline-code"},"$actions['slug']"),i(":")],-1)),e("div",At,[e("pre",{class:"docs-panel__code-pre"},[e("code",null,g(de))]),e("button",{type:"button",class:"docs-panel__code-copy",title:"Копировать",onClick:t[17]||(t[17]=n=>r("blade",de))},[l.value==="blade"?(c(),y(o(P),{key:0,class:"icon-3h icon-check-green"})):(c(),y(o(S),{key:1,class:"icon-3h"}))])]),t[45]||(t[45]=e("ul",{class:"docs-panel__list"},[e("li",null,[e("code",{class:"docs-panel__inline-code"},"$actions"),i(" — массив всех привязанных Actions")]),e("li",null,[i("Ключ — slug действия (например "),e("code",{class:"docs-panel__inline-code"},"'my-action'"),i(")")]),e("li",null,[i("Значение — массив, возвращённый из "),e("code",{class:"docs-panel__inline-code"},"handle()")])],-1))]),_:1},8,["expanded"]),x(M,{expanded:a.value[7],"onUpdate:expanded":t[19]||(t[19]=n=>a.value[7]=n)},{trigger:$(()=>[...t[46]||(t[46]=[i("Разрешённые модели",-1)])]),default:$(()=>[t[48]||(t[48]=e("p",null,"Код Actions проходит серверную валидацию. Разрешены только модели из whitelist:",-1)),e("table",Dt,[t[47]||(t[47]=e("thead",null,[e("tr",{class:"docs-panel__table-header-row"},[e("th",{class:"docs-panel__table-th"},"Модель"),e("th",{class:"docs-panel__table-th"},"Namespace"),e("th",{class:"docs-panel__table-th"},"Описание")])],-1)),e("tbody",Et,[(c(),C(B,null,F(p,n=>e("tr",{key:n.model},[e("td",Mt,[e("code",Vt,g(n.model),1)]),e("td",Rt,g(n.ns),1),e("td",Ut,g(n.desc),1)])),64))])]),t[49]||(t[49]=e("ul",{class:"docs-panel__list"},[e("li",null,[i("Модели из "),e("code",{class:"docs-panel__inline-code"},"App\\Models\\*"),i(" тоже разрешены")]),e("li",null,[i("Стандартные классы: "),e("code",{class:"docs-panel__inline-code"},"stdClass"),i(", "),e("code",{class:"docs-panel__inline-code"},"Carbon"),i(", "),e("code",{class:"docs-panel__inline-code"},"Collection")]),e("li",null,[i("Laravel-хелперы: "),e("code",{class:"docs-panel__inline-code"},"collect()"),i(", "),e("code",{class:"docs-panel__inline-code"},"optional()"),i(", "),e("code",{class:"docs-panel__inline-code"},"cache()"),i(", "),e("code",{class:"docs-panel__inline-code"},"now()")]),e("li",null,[i("Запрещены: "),e("code",{class:"docs-panel__inline-code docs-panel__inline-code--danger"},"eval"),i(", "),e("code",{class:"docs-panel__inline-code docs-panel__inline-code--danger"},"exec"),i(", "),e("code",{class:"docs-panel__inline-code docs-panel__inline-code--danger"},"system"),i(", "),e("code",{class:"docs-panel__inline-code docs-panel__inline-code--danger"},"file_get_contents"),i(", "),e("code",{class:"docs-panel__inline-code docs-panel__inline-code--danger"},"include/require")])],-1))]),_:1},8,["expanded"])]))}};function Ft({getParams:v,getGlobalFields:a}){const l=[{label:"title",detail:"string",info:"Заголовок страницы"},{label:"url",detail:"string",info:"URL страницы"},{label:"slug",detail:"string",info:"Slug страницы"},{label:"seo_data",detail:"array",info:"SEO-данные"},{label:"img",detail:"File|null",info:"Изображение"},{label:"status",detail:"string",info:"Статус (active/draft)"},{label:"views",detail:"int",info:"Количество просмотров"},{label:"parent",detail:"Page|null",info:"Родительская страница"},{label:"children",detail:"Collection",info:"Дочерние страницы"}],r=[{label:"get('key')",detail:"mixed",info:"Получить параметр"},{label:"input('key')",detail:"mixed",info:"Получить input"},{label:"all()",detail:"array",info:"Все данные запроса"},{label:"has('key')",detail:"bool",info:"Проверка наличия"},{label:"query('key')",detail:"mixed",info:"Query-параметр"},{label:"post('key')",detail:"mixed",info:"POST-параметр"},{label:"boolean('key')",detail:"bool",info:"Boolean-параметр"}],k=[{label:"page",detail:"Page",info:"Текущая страница"},{label:"request",detail:"Request",info:"HTTP-запрос"},{label:"global",detail:"array",info:"Глобальные поля"},{label:"blockData",detail:"array",info:"Данные полей блока"}];return u=>{const p=u.state.doc.lineAt(u.pos),b=p.text.slice(0,u.pos-p.from),t=b.match(/\$context->request->(\w*)$/);if(t){const d=t[1];return{from:u.pos-d.length,options:r.map(s=>({label:s.label,type:"method",detail:s.detail,info:s.info}))}}const n=b.match(/\$context->page->(\w*)$/);if(n){const d=n[1];return{from:u.pos-d.length,options:l.map(s=>({label:s.label,type:"property",detail:s.detail,info:s.info}))}}const D=b.match(/\$context->global\['([^']*)$/);if(D){const d=D[1],s=a();return{from:u.pos-d.length,options:s.map(f=>({label:f.key,type:"variable",detail:f.type||"mixed",info:f.name||f.key,apply:f.key+"']"}))}}const T=b.match(/\$context->(\w*)$/);if(T){const d=T[1];return{from:u.pos-d.length,options:k.map(s=>({label:s.label,type:"property",detail:s.detail,info:s.info}))}}const q=b.match(/\$params\['([^']*)$/);if(q){const d=q[1],s=v();return{from:u.pos-d.length,options:s.filter(f=>f.key.trim()).map(f=>({label:f.key,type:"variable",detail:f.type||"mixed",info:f.description||f.key,apply:f.key+"']"}))}}const _=b.match(/\$(\w*)$/);if(_){const d=_[1];return{from:u.pos-d.length,options:[{label:"params",type:"variable",detail:"array",info:"Входные параметры action"},{label:"context",type:"variable",detail:"ActionContext",info:"Контекст выполнения (page, request, global, blockData)"}]}}return null}}const U="templite:ai:";function zt(v){var a,l;return{kind:"action",action_id:((a=v.action)==null?void 0:a.id)??null,code:{php:((l=v.form)==null?void 0:l.code)??""}}}function It(v){const a=()=>{var p;return{type:"action",id:(p=v.action)==null?void 0:p.id}},l=({op:p,payload:b})=>{var t;if(p!=="update_action_code"||b.action_id!==((t=v.action)==null?void 0:t.id)||typeof b.code!="string")return!1;v.form.code=b.code,v.isDirty=!0},r=()=>{window.dispatchEvent(new CustomEvent(`${U}snapshot`,{detail:zt(v)}))},k=Ee(a,l),u=p=>k.accept(p.detail);return window.addEventListener(`${U}request-snapshot`,r),window.addEventListener(`${U}apply-op`,u),()=>{window.removeEventListener(`${U}request-snapshot`,r),window.removeEventListener(`${U}apply-op`,u),k.dispose()}}const Lt={class:"action-workspace__editor"},al={__name:"Edit",props:{action:{type:Object,default:null},defaultCode:{type:String,default:""},globalFieldDefinitions:{type:Array,default:()=>[]}},setup(v){const a=v,l=O(),{confirm:r}=Ve(),{cmsUrl:k}=Re();let u=null;function p(_){(_.ctrlKey||_.metaKey)&&_.key==="s"&&(_.preventDefault(),l.isSaving||l.saveAction())}Fe(()=>{l.init(a),window.addEventListener("keydown",p),u=It(l)}),ze(()=>{window.removeEventListener("keydown",p),u==null||u(),l.$reset()}),We(V(()=>l.isDirty));const b=[{key:"main",label:"Основные",icon:He},{key:"vars",label:"Переменные",icon:Oe}];async function t(){await r({title:"Удалить Action?",message:`"${l.form.name}" будет удалён.`,variant:"danger",confirmText:"Удалить"})&&l.deleteAction()}const n=V(()=>({main:"Основные",vars:"Переменные"})[l.activeSidePanel]||""),D=V(()=>l.activeSidePanel?`${l.sidePanelWidth+4}px`:"0px"),T=V(()=>l.isDocsPanelOpen?`${l.docsPanelWidth+4}px`:"0px"),q=Ft({getParams:()=>l.form.params,getGlobalFields:()=>l.globalFieldDefinitions});return(_,d)=>(c(),C("div",null,[x(he,{"back-url":o(k)("/actions"),title:o(l).form.name||"Action",tabs:b,"active-tab":o(l).activeSidePanel,"is-dirty":o(l).isDirty,"is-saving":o(l).isSaving,onTabChange:d[1]||(d[1]=s=>o(l).toggleSidePanel(s)),onSave:d[2]||(d[2]=s=>o(l).saveAction()),onDelete:t},{controls:$(()=>[e("button",{type:"button",class:L(["submenu__toggle",o(l).isDocsPanelOpen?"submenu__toggle--active":"submenu__toggle--inactive"]),title:"Документация",onClick:d[0]||(d[0]=s=>o(l).toggleDocsPanel())},[x(o(qe),{class:"icon-4"})],2)]),_:1},8,["back-url","title","active-tab","is-dirty","is-saving"]),x(J,{open:!!o(l).activeSidePanel,title:n.value,width:o(l).sidePanelWidth,side:"left",onClose:d[3]||(d[3]=s=>o(l).closeSidePanel()),onResize:d[4]||(d[4]=s=>o(l).setSidePanelWidth(s)),onResizeStart:d[5]||(d[5]=s=>o(l).isSidePanelResizing=!0),onResizeEnd:d[6]||(d[6]=s=>o(l).isSidePanelResizing=!1)},{default:$(()=>[o(l).activeSidePanel==="main"?(c(),y(Xe,{key:0})):R("",!0),o(l).activeSidePanel==="vars"?(c(),y(it,{key:1})):R("",!0)]),_:1},8,["open","title","width"]),x(J,{open:o(l).isDocsPanelOpen,title:"Документация",width:o(l).docsPanelWidth,side:"right",onClose:d[7]||(d[7]=s=>o(l).closeDocsPanel()),onResize:d[8]||(d[8]=s=>o(l).setDocsPanelWidth(s)),onResizeStart:d[9]||(d[9]=s=>o(l).isDocsPanelResizing=!0),onResizeEnd:d[10]||(d[10]=s=>o(l).isDocsPanelResizing=!1)},{default:$(()=>[x(Bt)]),_:1},8,["open","width"]),e("div",{class:L(["action-workspace",!(o(l).isSidePanelResizing||o(l).isDocsPanelResizing)&&"action-workspace--smooth"]),style:Ie({left:D.value,right:T.value})},[e("div",Lt,[x(we,{modelValue:o(l).form.code,"onUpdate:modelValue":d[11]||(d[11]=s=>o(l).form.code=s),language:"php","full-height":"","completion-source":o(q)},null,8,["modelValue","completion-source"])])],6)]))}};export{al as _};
