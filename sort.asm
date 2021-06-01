start	addi 1,0,10
		addi 2,0,200
		
		
		sw 1,2,0
		addi 2,2,1
init	beq 1,0,low
		sw 1,2,0
		addi 1,1,-1
		addi 2,2,1
		j init
low		addi 1,0,201


high	addi 2,0,210
first	beq 1,2,end
		add 3,0,1
sec		beq 3,2,pre
		lw 4,3,0
		lw 5,3,1
		slt 6,5,4
		addi 3,3,1
		beq 6,0,sec
		
		
		sw 4,3,0
		sw 5,3,-1
		j sec
pre		addi 2,2,-1
		j first
end 	halt