(() => {
    const job=document.getElementById('gsm-bulk-job'); if (!job) return;
    let stage=job.dataset.stage, busy=false, timer=null, failed=0, pending=null;
    const status=document.getElementById('gsm-bulk-status');
    function controls() {
        job.querySelector('[data-control=pause]').hidden=stage!=='running';
        job.querySelector('[data-control=resume]').hidden=stage!=='paused';
        job.querySelector('[data-control=retry]').hidden=stage!=='done'||failed===0;
        status.textContent=({running:'正在分批处理，请保持页面打开；关闭后可返回继续。',paused:'任务已暂停。',done:'处理完成。'})[stage]||'等待开始';
    }
    async function request(control='step') {
        if (busy) return; busy=true; clearTimeout(timer);
        try {
            const body=new URLSearchParams({action:'gsm_bulk_step',gsm_job:job.dataset.token,nonce:job.dataset.nonce,gsm_brand:job.dataset.brand,control});
            const response=await fetch(job.dataset.url,{method:'POST',credentials:'same-origin',body});
            const payload=await response.json(); if (!response.ok||!payload.success) throw new Error(payload.data?.message||'处理请求失败，请刷新后继续。');
            const data=payload.data;stage=data.stage;failed=data.counts.failed;
            document.getElementById('gsm-bulk-progress').value=data.processed;
            document.getElementById('gsm-bulk-counts').textContent=`已处理 ${data.processed}/${data.total}；成功 ${data.counts.success}，跳过 ${data.counts.skipped}，失败 ${failed}`;
            document.getElementById('gsm-bulk-rows').innerHTML=data.rows;
            controls(); if (data.busy) status.textContent='正在等待其他操作完成…';
            if (stage==='running') timer=setTimeout(()=>request(),data.busy?1000:100);
        } catch (error) {
            // The current item may have completed before a dropped response. Resume is idempotent.
            stage='paused';controls();status.textContent=error.message||'连接中断，请刷新页面继续任务。';
        } finally {busy=false;if (pending) {const next=pending;pending=null;clearTimeout(timer);request(next);}}
    }
    job.addEventListener('click',event=>{
        const button=event.target.closest('[data-control]'); if (!button) return;
        if (busy) {if (button.dataset.control==='pause') {pending='pause';status.textContent='将在当前商品处理完成后暂停。';}return;}
        request(button.dataset.control);
    });
    failed=Number((document.getElementById('gsm-bulk-counts').textContent.match(/失败\s*(\d+)/)||[])[1]||0);
    controls();if (stage==='running') request();
})();
