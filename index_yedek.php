<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nakliye Hakediş Dashboard</title>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
body{
    font-family:Segoe UI;
    background:#f3f6fb;
    margin:0;
    padding:20px;
}
h1{
    margin:0 0 20px 0;
}
.card-area{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:15px;
    margin-bottom:20px;
}
.card{
    background:white;
    padding:20px;
    border-radius:12px;
    box-shadow:0 2px 10px rgba(0,0,0,.08);
}
.card-title{
    color:#666;
    font-size:13px;
}
.card-value{
    font-size:28px;
    font-weight:bold;
    margin-top:10px;
}
.panel{
    background:white;
    padding:20px;
    border-radius:12px;
    margin-bottom:20px;
    box-shadow:0 2px 10px rgba(0,0,0,.08);
}
input[type=file]{
    padding:10px;
}
table{
    border-collapse:collapse;
    width:100%;
    font-size:12px;
}
th{
    background:#dce9f9;
    padding:8px;
    border:1px solid #bfcfe5;
}
td{
    padding:7px;
    border:1px solid #d7e2f0;
}
.yellow{
    background:#fff56e;
    font-weight:bold;
}
.red{
    color:#c40000;
    font-weight:bold;
}
.green{
    color:#008f39;
    font-weight:bold;
}
.chart-wrap{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
}
canvas{
    background:white;
    border-radius:12px;
    padding:10px;
}
</style>
</head>
<body>

<h1>Nakliye Motorin Hakediş Dashboard</h1>

<div class="panel">
    <input type="file" id="excelFile">
</div>

<div class="card-area">

    <div class="card">
        <div class="card-title">Toplam Sefer</div>
        <div class="card-value" id="toplamSefer">0</div>
    </div>

    <div class="card">
        <div class="card-title">KDV Hariç Toplam</div>
        <div class="card-value" id="kdvHaric">₺0</div>
    </div>

    <div class="card">
        <div class="card-title">Toplam KDV</div>
        <div class="card-value" id="toplamKdv">₺0</div>
    </div>

    <div class="card">
        <div class="card-title">Genel Toplam</div>
        <div class="card-value" id="genelToplam">₺0</div>
    </div>

</div>

<div class="chart-wrap">
    <canvas id="chart1"></canvas>
    <canvas id="chart2"></canvas>
</div>

<br>

<div class="panel">
    <div style="overflow:auto;max-height:700px;">
        <table id="tbl"></table>
    </div>
</div>

<script>

let chart1;
let chart2;

document.getElementById('excelFile').addEventListener('change',handleFile,false);

function tl(v){
    return v.toLocaleString('tr-TR',{
        style:'currency',
        currency:'TRY'
    });
}

function num(v){

    if(typeof v==="number") return v;

    v=String(v)
    .replace(/₺/g,'')
    .replace(/\./g,'')
    .replace(',', '.')
    .replace('%','')
    .trim();

    return parseFloat(v)||0;
}

function handleFile(e){

    const file=e.target.files[0];

    const reader=new FileReader();

    reader.onload=function(ev){

        const data=new Uint8Array(ev.target.result);

        const workbook=XLSX.read(data,{type:'array'});

        const sheet=workbook.Sheets[workbook.SheetNames[0]];

        const json=XLSX.utils.sheet_to_json(sheet,{defval:''});

        hesapla(json);
    }

    reader.readAsArrayBuffer(file);
}

function hesapla(data){

    let html='';

    html+=`
    <tr>
        <th>Sıra</th>
        <th>Tarih</th>
        <th>Çıkış</th>
        <th>Sevk</th>
        <th>Birim Fiyat</th>
        <th>%40</th>
        <th>Baz</th>
        <th>Günlük</th>
        <th>Fark</th>
        <th>Fark %</th>
        <th>Zam / İndirim</th>
        <th>KDV Hariç</th>
        <th>KDV</th>
        <th>Tevkifat</th>
        <th>Toplam</th>
    </tr>
    `;

    let toplamNet=0;
    let toplamKdv=0;
    let toplamGenel=0;

    let firmaToplam={};

    let tarihToplam={};

    data.forEach((r,i)=>{

        let birim=num(r["Birim Fiyat"]);
        let baz=num(r["Motorin Baz Fiyatı"]);
        let gunluk=num(r["Günlük Motorin Fiyatı"]);

        let fark=gunluk-baz;

        let farkYuzde=(fark/baz)*100;

        let yakit40=birim*0.40;

        let zam=0;

        /*
        KRİTİK MANTIK

        Eğer fark %7 geçerse:
        ama excelde birim fiyat zaten artmışsa
        ikinci kez zam uygulama

        */

        let oncekiBirim=0;

        if(i>0){

            oncekiBirim=num(data[i-1]["Birim Fiyat"]);

        }

        let fiyatDegismis=birim>oncekiBirim && oncekiBirim>0;

        if(Math.abs(farkYuzde)>=7 && !fiyatDegismis){

            zam=yakit40*(farkYuzde/100);

        }

        let net=birim+zam;

        let kdv=net*0.20;

        let tev=kdv*0.20;

        let genel=net+kdv-tev;

        toplamNet+=net;
        toplamKdv+=kdv;
        toplamGenel+=genel;

        let firma=r["Çıkış Yeri"];

        if(!firmaToplam[firma]) firmaToplam[firma]=0;

        firmaToplam[firma]+=genel;

        let tarih=r["Nakliye Tarihi"];

        if(!tarihToplam[tarih]) tarihToplam[tarih]=0;

        tarihToplam[tarih]+=genel;

        html+=`
        <tr>

            <td>${i+1}</td>

            <td>${r["Nakliye Tarihi"]}</td>

            <td>${r["Çıkış Yeri"]}</td>

            <td>${r["Sevk Yeri"]}</td>

            <td class="yellow">${tl(birim)}</td>

            <td>${tl(yakit40)}</td>

            <td>${baz}</td>

            <td>${gunluk}</td>

            <td>${fark.toFixed(2)}</td>

            <td class="${farkYuzde>=7?'red':'green'}">
                ${farkYuzde.toFixed(2)}%
            </td>

            <td>${tl(zam)}</td>

            <td class="yellow">${tl(net)}</td>

            <td>${tl(kdv)}</td>

            <td>${tl(tev)}</td>

            <td>${tl(genel)}</td>

        </tr>
        `;
    });

    document.getElementById('tbl').innerHTML=html;

    document.getElementById('toplamSefer').innerHTML=data.length;

    document.getElementById('kdvHaric').innerHTML=tl(toplamNet);

    document.getElementById('toplamKdv').innerHTML=tl(toplamKdv);

    document.getElementById('genelToplam').innerHTML=tl(toplamGenel);

    grafikler(firmaToplam,tarihToplam);
}

function grafikler(firmaToplam,tarihToplam){

    if(chart1) chart1.destroy();

    if(chart2) chart2.destroy();

    chart1=new Chart(document.getElementById('chart1'),{

        type:'bar',

        data:{

            labels:Object.keys(firmaToplam),

            datasets:[{

                label:'Firma Bazlı Toplam',

                data:Object.values(firmaToplam)

            }]
        }
    });

    chart2=new Chart(document.getElementById('chart2'),{

        type:'line',

        data:{

            labels:Object.keys(tarihToplam),

            datasets:[{

                label:'Günlük Toplam',

                data:Object.values(tarihToplam)

            }]
        }
    });
}

</script>

</body>
</html>